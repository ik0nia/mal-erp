package winmentor

import (
	"errors"
	"fmt"
	"runtime"
	"strings"
	"syscall"
	"unsafe"

	"github.com/go-ole/go-ole"
	"github.com/go-ole/go-ole/oleutil"
)

var (
	ErrNotConnected = errors.New("winmentor: not connected to DocImpServer")
	ErrSetFirma     = errors.New("winmentor: failed to set company name")
	ErrSetLuna      = errors.New("winmentor: failed to set work month")
)

// Client wraps a connection to the DocImpServer COM object.
// All COM operations are dispatched to a dedicated goroutine that owns the OS thread.
type Client struct {
	obj     *ole.IDispatch // only accessed from the COM goroutine
	unknown *ole.IUnknown  // only accessed from the COM goroutine
	vtbl    *vtableInfo    // only accessed from the COM goroutine
	comCh   chan comReq    // channel for dispatching work to the COM goroutine
	done    chan struct{}  // closed when the COM goroutine exits
}

// NewClient creates a new COM connection to DocImpServer.
// It spawns a dedicated goroutine that owns the COM thread. The client is safe
// to use from any goroutine.
func NewClient() (*Client, error) {
	c := &Client{
		comCh: make(chan comReq),
		done:  make(chan struct{}),
	}

	errCh := make(chan error, 1)
	go c.comLoop(errCh)

	if err := <-errCh; err != nil {
		return nil, err
	}
	return c, nil
}

// comLoop runs on a dedicated goroutine, locked to an OS thread.
// It initializes COM, creates the DocImpServer object, and processes requests
// until the channel is closed.
func (c *Client) comLoop(errCh chan<- error) {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()

	if err := ole.CoInitialize(0); err != nil {
		oleErr, ok := err.(*ole.OleError)
		if !ok || oleErr.Code() != 0x00000001 { // S_FALSE = already initialized
			errCh <- fmt.Errorf("CoInitialize: %w", err)
			return
		}
	}
	defer ole.CoUninitialize()

	unknown, err := oleutil.CreateObject("DocImpServer.DocImpObject")
	if err != nil {
		errCh <- fmt.Errorf("CreateObject DocImpServer.DocImpObjectClass: %w", err)
		return
	}

	disp, err := unknown.QueryInterface(ole.IID_IDispatch)
	if err != nil {
		unknown.Release()
		errCh <- fmt.Errorf("QueryInterface IDispatch: %w", err)
		return
	}

	vtbl, _ := newVTableInfo(disp)
	c.vtbl = vtbl

	c.obj = disp
	c.unknown = unknown
	close(errCh) // signal success

	// Process requests until channel is closed.
	for req := range c.comCh {
		req.fn()
		close(req.done)
	}

	// Cleanup COM resources on the thread that created them.
	if c.vtbl != nil {
		c.vtbl.Release()
		c.vtbl = nil
	}
	disp.Release()
	unknown.Release()
	c.obj = nil
	c.unknown = nil
	close(c.done)
}

// Close releases the COM object, uninitializes COM, and terminates the COM goroutine.
// After Close returns, the client must not be used.
func (c *Client) Close() {
	close(c.comCh)
	<-c.done
}

// --- Session Setup ---

// GetListaFirme returns the list of company short names available in the WinMENTOR data directory.
func (c *Client) GetListaFirme() ([]string, error) {
	return c.callReturningStrings("GetListaFirme")
}

// GetListaLuni returns the list of work months (format "yyyy_mm") for the given company.
func (c *Client) GetListaLuni(numeFirma string) ([]string, error) {
	return c.callReturningStrings("GetListaLuni", numeFirma)
}

// SetNumeFirma sets the active company. Returns nil on success.
func (c *Client) SetNumeFirma(numeFirma string) error {
	result, err := c.callMethodInt("SetNumeFirma", numeFirma)
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("%w: %s", ErrSetFirma, strings.Join(errs, "; "))
		}
		return ErrSetFirma
	}
	return nil
}

// SetLunaLucru sets the working month (year, month). Returns nil on success.
func (c *Client) SetLunaLucru(an, luna int) error {
	result, err := c.callMethodInt("SetLunaLucru", an, luna)
	if err != nil {
		return err
	}
	if result == 0 {
		errs, _ := c.GetListaErori()
		if len(errs) > 0 {
			return fmt.Errorf("%w: %s", ErrSetLuna, strings.Join(errs, "; "))
		}
		return ErrSetLuna
	}
	return nil
}

// GetListaErori returns the list of error messages from the last operation.
func (c *Client) GetListaErori() ([]string, error) {
	return c.callReturningStrings("GetListaErori")
}

// SetDocsData sends a document data packet (array of strings) to the server.
// This must be called before DateValide/ImportaFacturi/ComenziValide/etc.
//
// DocImpServer expects a VarArray of OleStr (VARIANT containing SAFEARRAY of BSTR).
// IDispatch::Invoke doesn't work with this server — we must use vtable.
// The Delphi dual-interface declares: SetDocsData([in] VARIANT Data)
// On 32-bit COM, [in] VARIANT is passed by reference (pointer) on the stack.
func (c *Client) SetDocsData(lines []string) (err error) {
	c.comDo(func() {
		if c.vtbl == nil {
			// Fallback: try IDispatch (unlikely to work but safe)
			_, err = oleutil.CallMethod(c.obj, "SetDocsData", lines)
			if err != nil {
				err = fmt.Errorf("SetDocsData IDispatch: %w", err)
			}
			return
		}

		m, ok := c.vtbl.methods["SetDocsData"]
		if !ok {
			err = fmt.Errorf("SetDocsData not found in vtable")
			return
		}

		// Build SAFEARRAY(BSTR) — DocImpServer expects VT_ARRAY|VT_BSTR
		sa, saErr := createBstrSafeArray(lines)
		if saErr != nil {
			err = fmt.Errorf("SetDocsData: SAFEARRAY creation failed: %w", saErr)
			return
		}

		// Build VARIANT on stack: VT_ARRAY | VT_BSTR
		// Delphi dual-interface: [in] VARIANT is passed by value on stack (16 bytes on 32-bit)
		// Layout: [VT:2][pad:6][pSA:4][pad:4] = 16 bytes = 4 x uint32
		var raw [16]byte
		vt := uint16(ole.VT_ARRAY | ole.VT_BSTR) // 0x2008
		*(*uint16)(unsafe.Pointer(&raw[0])) = vt
		*(*uintptr)(unsafe.Pointer(&raw[8])) = uintptr(unsafe.Pointer(sa))

		// Decompose into 4 stack-sized words
		words := (*[4]uintptr)(unsafe.Pointer(&raw[0]))

		// Get vtable function address
		vtblPtr := *(*uintptr)(unsafe.Pointer(c.vtbl.ifacePtr))
		funcAddr := *(*uintptr)(unsafe.Pointer(vtblPtr + m.oVft))

		// Call with VARIANT by value: this + 4 words of VARIANT
		ret, _, _ := syscall.SyscallN(funcAddr,
			c.vtbl.ifacePtr,
			words[0], words[1], words[2], words[3])
		if hr := int32(ret); hr < 0 {
			err = fmt.Errorf("SetDocsData vtable failed: HRESULT 0x%08X", uint32(hr))
		}

		_ = m
	})
	return
}

// CallMethodIntExported is a public wrapper around callMethodInt for use by API handlers.
func (c *Client) CallMethodIntExported(name string, args ...interface{}) (int, error) {
	return c.callMethodInt(name, args...)
}

// CallWithVariantArray calls a vtable method that takes [in] VARIANT (SAFEARRAY of BSTR)
// and returns [out,retval] Integer. Used for SetReceivingList, SetPickedList, etc.
// Same SAFEARRAY trick as SetDocsData but with a return value.
func (c *Client) CallWithVariantArray(name string, lines []string) (result int, err error) {
	c.comDo(func() {
		if c.vtbl == nil {
			err = fmt.Errorf("%s requires vtable", name)
			return
		}
		m, ok := c.vtbl.methods[name]
		if !ok {
			err = fmt.Errorf("%s not found in vtable", name)
			return
		}

		sa, saErr := createBstrSafeArray(lines)
		if saErr != nil {
			err = fmt.Errorf("%s: SAFEARRAY failed: %w", name, saErr)
			return
		}

		// Build VARIANT: VT_ARRAY | VT_BSTR
		var raw [16]byte
		*(*uint16)(unsafe.Pointer(&raw[0])) = uint16(ole.VT_ARRAY | ole.VT_BSTR)
		*(*uintptr)(unsafe.Pointer(&raw[8])) = uintptr(unsafe.Pointer(sa))
		words := (*[4]uintptr)(unsafe.Pointer(&raw[0]))

		// vtable: HRESULT Method([in] VARIANT, [out,retval] long*)
		var retVal int32
		vtblPtr := *(*uintptr)(unsafe.Pointer(c.vtbl.ifacePtr))
		funcAddr := *(*uintptr)(unsafe.Pointer(vtblPtr + m.oVft))

		ret, _, _ := syscall.SyscallN(funcAddr,
			c.vtbl.ifacePtr,
			words[0], words[1], words[2], words[3],
			uintptr(unsafe.Pointer(&retVal)))
		if hr := int32(ret); hr < 0 {
			err = fmt.Errorf("%s vtable failed: HRESULT 0x%08X", name, uint32(hr))
			return
		}
		result = int(retVal)

		_ = m
	})
	return
}

// VTableMethods returns the vtable method map for debugging.
func (c *Client) VTableMethods() map[string]int {
	if c.vtbl == nil {
		return nil
	}
	result := make(map[string]int)
	for name, m := range c.vtbl.methods {
		result[name] = m.nParams
	}
	return result
}

// --- Configuration ---

// SetIDPartField sets the field used for partner identification.
// Valid values: "CodExtern", "CodFiscal", "CodIntern".
func (c *Client) SetIDPartField(fieldName string) error {
	return c.callMethodVoid("SetIDPartField", fieldName)
}

// SetIDArtField sets the field used for article identification.
// Valid values: "CodExtern", "CodIntern".
func (c *Client) SetIDArtField(fieldName string) error {
	return c.callMethodVoid("SetIDArtField", fieldName)
}

// GetVersiuni calls the COM method that returns WinMENTOR and server versions.
// DLL signature: GetVersiuni(out VerMentor, VerServer: Double): Integer
// COM vtable: HRESULT GetVersiuni([out] double*, [out] double*, [out,retval] long*)
// Returns (result_code, mentor_version, server_version, error).
func (c *Client) GetVersiuni() (resultCode int, verMentor float64, verServer float64, err error) {
	c.comDo(func() {
		if c.vtbl != nil {
			var vMentor, vServer float64
			var retVal int32
			_, err = c.vtblCall("GetVersiuni",
				unsafe.Pointer(&vMentor),
				unsafe.Pointer(&vServer),
				unsafe.Pointer(&retVal))
			if err == nil {
				resultCode = int(retVal)
				verMentor = vMentor
				verServer = vServer
				return
			}
		}

		var vMentor, vServer float64
		mentorVariant := ole.NewVariant(ole.VT_R8|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&vMentor))))
		serverVariant := ole.NewVariant(ole.VT_R8|ole.VT_BYREF, int64(uintptr(unsafe.Pointer(&vServer))))

		var v *ole.VARIANT
		v, err = c.rawCall("GetVersiuni", &mentorVariant, &serverVariant)
		if err != nil {
			return
		}
		defer v.Clear()

		resultCode = int(v.Val)
		verMentor = vMentor
		verServer = vServer
	})
	return
}

// GetStringConstanta returns a string constant by ID and Symbol.
func (c *Client) GetStringConstanta(id int, simbol string) (string, error) {
	return c.callMethodString("GetStringConstanta", id, simbol)
}
