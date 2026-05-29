//go:build windows

package winmentor

import (
	"fmt"
	"syscall"
	"unsafe"

	"github.com/go-ole/go-ole"
)

// COM structures not present in go-ole
type funcdesc struct {
	Memid             int32
	Lprgscode         uintptr
	LprgelemdescParam uintptr
	Funckind          int32
	Invkind           int32
	Callconv          int32
	CParams           int16
	CParamsOpt        int16
	OVft              int16
	CScodes           int16
	ElemdescFunc      elemdesc
	WFuncFlags        uint16
}

type elemdesc struct {
	Tdesc typedesc
	Param paramdesc
}

type typedesc struct {
	Union uintptr
	Vt    uint16
}

type paramdesc struct {
	Pptrsafe    uintptr
	WParamFlags uint16
}

func ptrSize() uintptr {
	return unsafe.Sizeof(uintptr(0))
}

type vtableInfo struct {
	ifacePtr uintptr
	methods  map[string]vtableMethod
}

type vtableMethod struct {
	name    string
	oVft    uintptr // native byte offset in vtable
	dispID  int32
	nParams int // total params in vtable (including retval, excluding this)
}

func newVTableInfo(disp *ole.IDispatch) (*vtableInfo, error) {
	if disp == nil {
		return nil, fmt.Errorf("dispatch pointer is nil")
	}

	var ti *ole.ITypeInfo
	// GetTypeInfo(this, iTInfo, lcid, ppTInfo) - 4 args
	hr, _, _ := syscall.Syscall6(
		uintptr(disp.VTable().GetTypeInfo),
		4,
		uintptr(unsafe.Pointer(disp)),
		0,
		0,
		uintptr(unsafe.Pointer(&ti)),
		0, 0)
	if hr != 0 {
		return nil, fmt.Errorf("GetTypeInfo failed: 0x%08X", hr)
	}
	defer ti.Release()

	ta, err := ti.GetTypeAttr()
	if err != nil {
		return nil, fmt.Errorf("GetTypeAttr: %w", err)
	}
	nFuncs := int(ta.CFuncs)
	iid := ta.Guid
	typekind := ta.Typekind
	tiReleaseTypeAttr(ti, ta)

	var dualTI *ole.ITypeInfo
	if typekind == 4 { // TKIND_DISPATCH
		href, err := tiGetRefTypeOfImplType(ti, -1)
		if err == nil {
			dualTI, err = tiGetRefTypeInfo(ti, href)
			if err == nil {
				dta, err2 := dualTI.GetTypeAttr()
				if err2 == nil {
					nFuncs = int(dta.CFuncs)
					iid = dta.Guid
					tiReleaseTypeAttr(dualTI, dta)
				}
			}
		}
	}

	enumTI := ti
	if dualTI != nil {
		enumTI = dualTI
		defer dualTI.Release()
	}

	// QueryInterface for the actual interface
	unk, err := disp.QueryInterface(&iid)
	if err != nil {
		return nil, fmt.Errorf("QueryInterface for native interface %v: %w", iid, err)
	}
	// We MUST release unk later. vi.Release() does this.
	rawIface := uintptr(unsafe.Pointer(unk))

	methods := make(map[string]vtableMethod)
	for i := 0; i < nFuncs; i++ {
		fd, err := tiGetFuncDesc(enumTI, i)
		if err != nil {
			continue
		}

		if fd.Funckind > 1 { // Skip non-vtable methods
			tiReleaseFuncDesc(enumTI, fd)
			continue
		}

		// Type library always reports 32-bit offsets (4 bytes per entry).
		// Convert to vtable slot index, then to native pointer-sized offset.
		slotIndex := uintptr(fd.OVft) / 4

		// Skip IUnknown (slots 0-2) and IDispatch (slots 3-6)
		if slotIndex < 7 {
			tiReleaseFuncDesc(enumTI, fd)
			continue
		}

		// Convert to native offset for the current platform's pointer size
		nativeOffset := slotIndex * ptrSize()

		name, err := tiGetMethodName(enumTI, fd.Memid)
		if err != nil {
			tiReleaseFuncDesc(enumTI, fd)
			continue
		}

		methods[name] = vtableMethod{
			name:    name,
			oVft:    nativeOffset,
			dispID:  fd.Memid,
			nParams: int(fd.CParams),
		}

		tiReleaseFuncDesc(enumTI, fd)
	}

	return &vtableInfo{
		ifacePtr: rawIface,
		methods:  methods,
	}, nil
}

func (vi *vtableInfo) Release() {
	if vi.ifacePtr != 0 {
		unk := (*ole.IUnknown)(unsafe.Pointer(vi.ifacePtr))
		unk.Release()
		vi.ifacePtr = 0
	}
}

func (vi *vtableInfo) Call(methodName string, params ...uintptr) (uintptr, error) {
	if vi.ifacePtr == 0 {
		return 0, fmt.Errorf("vtableInfo released")
	}
	m, ok := vi.methods[methodName]
	if !ok {
		return 0, fmt.Errorf("method %s not found in vtable", methodName)
	}

	// Get vtable pointer
	vtblPtr := *(*uintptr)(unsafe.Pointer(vi.ifacePtr))
	// Get function address from vtable at the native offset
	funcAddr := *(*uintptr)(unsafe.Pointer(vtblPtr + m.oVft))

	// Prepare arguments: the first argument is always the interface pointer (this)
	args := make([]uintptr, 0, len(params)+1)
	args = append(args, vi.ifacePtr)
	args = append(args, params...)

	ret, _, _ := syscall.SyscallN(funcAddr, args...)

	// ret is the HRESULT. Check for failure (negative = error).
	hr := int32(ret)
	if hr < 0 {
		return ret, fmt.Errorf("vtable call %s failed: HRESULT 0x%08X", methodName, uint32(hr))
	}
	return ret, nil
}

func (c *Client) vtblCall(name string, args ...interface{}) (uintptr, error) {
	if c.vtbl == nil {
		return 0, fmt.Errorf("vtable info not available")
	}

	if _, ok := c.vtbl.methods[name]; !ok {
		return 0, fmt.Errorf("method %s not found in vtable", name)
	}

	vargs := make([]uintptr, 0, len(args))
	for _, arg := range args {
		switch v := arg.(type) {
		case int:
			vargs = append(vargs, uintptr(v))
		case int32:
			vargs = append(vargs, uintptr(v))
		case float64:
			// For Delphi Double (8 bytes), we need to pass it carefully.
			// On 32-bit, it takes two stack slots.
			if ptrSize() == 4 {
				u := *(*[2]uintptr)(unsafe.Pointer(&v))
				vargs = append(vargs, u[0], u[1])
			} else {
				vargs = append(vargs, *(*uintptr)(unsafe.Pointer(&v)))
			}
		case string:
			ptr := ole.SysAllocString(v)
			if ptr != nil {
				defer ole.SysFreeString((*int16)(unsafe.Pointer(ptr)))
			}
			vargs = append(vargs, uintptr(unsafe.Pointer(ptr)))
		case bool:
			if v {
				vargs = append(vargs, 1)
			} else {
				vargs = append(vargs, 0)
			}
		case *ole.VARIANT:
			vargs = append(vargs, uintptr(unsafe.Pointer(v)))
		case unsafe.Pointer:
			vargs = append(vargs, uintptr(v))
		default:
			return 0, fmt.Errorf("unsupported vtable arg type: %T", arg)
		}
	}

	return c.vtbl.Call(name, vargs...)
}

// Helper functions for ITypeInfo methods not provided by go-ole

func tiReleaseTypeAttr(ti *ole.ITypeInfo, ta *ole.TYPEATTR) {
	syscall.Syscall(ti.VTable().ReleaseTypeAttr, 2, uintptr(unsafe.Pointer(ti)), uintptr(unsafe.Pointer(ta)), 0)
}

func tiGetFuncDesc(ti *ole.ITypeInfo, index int) (*funcdesc, error) {
	var fd *funcdesc
	hr, _, _ := syscall.Syscall(ti.VTable().GetFuncDesc, 3, uintptr(unsafe.Pointer(ti)), uintptr(index), uintptr(unsafe.Pointer(&fd)))
	if hr != 0 {
		return nil, fmt.Errorf("GetFuncDesc failed: 0x%08X", hr)
	}
	return fd, nil
}

func tiReleaseFuncDesc(ti *ole.ITypeInfo, fd *funcdesc) {
	syscall.Syscall(ti.VTable().ReleaseFuncDesc, 2, uintptr(unsafe.Pointer(ti)), uintptr(unsafe.Pointer(fd)), 0)
}

func tiGetNames(ti *ole.ITypeInfo, memid int32, count int) ([]string, error) {
	names := make([]*uint16, count)
	var fetched uint32
	hr, _, _ := syscall.Syscall6(ti.VTable().GetNames, 5, uintptr(unsafe.Pointer(ti)), uintptr(memid), uintptr(unsafe.Pointer(&names[0])), uintptr(count), uintptr(unsafe.Pointer(&fetched)), 0)
	if hr != 0 {
		return nil, fmt.Errorf("GetNames failed: 0x%08X", hr)
	}
	res := make([]string, fetched)
	for i := 0; i < int(fetched); i++ {
		res[i] = ole.BstrToString(names[i])
		ole.SysFreeString((*int16)(unsafe.Pointer(names[i])))
	}
	return res, nil
}

func tiGetMethodName(ti *ole.ITypeInfo, memid int32) (string, error) {
	names, err := tiGetNames(ti, memid, 1)
	if err != nil || len(names) == 0 {
		return "", err
	}
	return names[0], nil
}

func tiGetRefTypeOfImplType(ti *ole.ITypeInfo, index int) (uint32, error) {
	var href uint32
	hr, _, _ := syscall.Syscall(ti.VTable().GetRefTypeOfImplType, 3, uintptr(unsafe.Pointer(ti)), uintptr(index), uintptr(unsafe.Pointer(&href)))
	if hr != 0 {
		return 0, fmt.Errorf("GetRefTypeOfImplType failed: 0x%08X", hr)
	}
	return href, nil
}

func tiGetRefTypeInfo(ti *ole.ITypeInfo, href uint32) (*ole.ITypeInfo, error) {
	var res *ole.ITypeInfo
	hr, _, _ := syscall.Syscall(ti.VTable().GetRefTypeInfo, 3, uintptr(unsafe.Pointer(ti)), uintptr(href), uintptr(unsafe.Pointer(&res)))
	if hr != 0 {
		return nil, fmt.Errorf("GetRefTypeInfo failed: 0x%08X", hr)
	}
	return res, nil
}

// createBstrSafeArray builds a 1-dimensional SAFEARRAY of BSTR from a Go []string.
func createBstrSafeArray(ss []string) (*ole.SafeArray, error) {
	modoleaut32 := syscall.NewLazyDLL("oleaut32.dll")
	procCreate := modoleaut32.NewProc("SafeArrayCreateVector")
	procPut := modoleaut32.NewProc("SafeArrayPutElement")

	sa, _, _ := procCreate.Call(uintptr(8), 0, uintptr(len(ss))) // VT_BSTR = 8
	if sa == 0 {
		return nil, fmt.Errorf("SafeArrayCreateVector returned nil")
	}

	for i, s := range ss {
		bstr := ole.SysAllocString(s)
		idx := int32(i)
		hr, _, _ := procPut.Call(sa, uintptr(unsafe.Pointer(&idx)), uintptr(unsafe.Pointer(bstr)))
		if int32(hr) != 0 {
			return nil, fmt.Errorf("SafeArrayPutElement[%d] failed: 0x%08X", i, uint32(hr))
		}
	}
	return (*ole.SafeArray)(unsafe.Pointer(sa)), nil
}

// createVariantSafeArray builds a SAFEARRAY of VARIANT, where each element
// is a VT_BSTR VARIANT. This matches how C#/.NET passes string[] to Delphi OleVariant.
func createVariantSafeArray(ss []string) (*ole.SafeArray, error) {
	modoleaut32 := syscall.NewLazyDLL("oleaut32.dll")
	procCreate := modoleaut32.NewProc("SafeArrayCreateVector")
	procPut := modoleaut32.NewProc("SafeArrayPutElement")

	// VT_VARIANT = 12, each element is 16 bytes (sizeof VARIANT)
	sa, _, _ := procCreate.Call(uintptr(12), 0, uintptr(len(ss)))
	if sa == 0 {
		return nil, fmt.Errorf("SafeArrayCreateVector(VT_VARIANT) returned nil")
	}

	for i, s := range ss {
		// Create a VT_BSTR VARIANT for this string
		bstr := ole.SysAllocString(s)
		var v ole.VARIANT
		v.VT = ole.VT_BSTR
		v.Val = int64(uintptr(unsafe.Pointer(bstr)))

		idx := int32(i)
		hr, _, _ := procPut.Call(sa, uintptr(unsafe.Pointer(&idx)), uintptr(unsafe.Pointer(&v)))
		if int32(hr) != 0 {
			return nil, fmt.Errorf("SafeArrayPutElement[%d] failed: 0x%08X", i, uint32(hr))
		}
	}
	return (*ole.SafeArray)(unsafe.Pointer(sa)), nil
}
