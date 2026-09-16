import struct, json
data=open('/tmp/DocImpServer.tlb','rb').read()
N=len(data)
def i32(o): return struct.unpack_from('<i',data,o)[0] if 0<=o and o+4<=N else 0
def u16(o): return struct.unpack_from('<H',data,o)[0]
NAMETAB=1352; TYPEDESC=8100; NLEN=6660
def name_at(rel):
    if rel is None or rel<0: return None
    b=NAMETAB+rel
    if b+12>N: return None
    ln=data[b+8]
    return data[b+12:b+12+ln].decode('latin1')
# Pascal type + a "format" note
VT={0:('','void'),2:('Smallint','int16'),3:('Integer','int32'),4:('Single','float32'),
    5:('Double','float64'),6:('Currency','currency'),7:('TDateTime','OLE date (float; 1899-12-30 base)'),
    8:('WideString','string (BSTR)'),9:('IDispatch','object'),10:('SCODE','hresult'),
    11:('WordBool','bool'),12:('OleVariant','variant / VarArray'),13:('IUnknown','object'),
    16:('Shortint','int8'),17:('Byte','uint8'),18:('Word','uint16'),19:('LongWord','uint32'),
    22:('Integer','int32'),23:('Cardinal','uint32'),24:('','void'),25:('HRESULT','hresult'),
    26:('Ptr','pointer'),27:('safearray','array')}
def vpair(v): return VT.get(v,('VT%d'%v,'?'))
def dtype(t,d=0):
    if d>6: return ('?','?')
    if t<0: return vpair(t & 0x0fff)
    v=u16(TYPEDESC+t); ref=i32(TYPEDESC+t+4)
    if v in (26,0x1a): return dtype(ref,d+1)
    if v==27:
        b,_=dtype(ref,d+1); return ('safearray of %s'%b,'array')
    return vpair(v)

# func names in vtable order (hreftype==0, drop interface)
o=NAMETAB; funcnames=[]
while o<NAMETAB+NLEN:
    hreftype=i32(o); ln=data[o+8]
    if ln==0 or ln>64: break
    if hreftype==0: funcnames.append(data[o+12:o+12+ln].decode('latin1'))
    o+=(12+ln+3)&~3
funcnames=funcnames[1:]

memoffset=8132; cFuncs=171
rec=memoffset+4; out=[]
for fi in range(cFuncs):
    reclen=i32(rec)&0xffff
    if reclen<24 or rec+reclen>N: break
    ret=i32(rec+4); vtbloff=u16(rec+12); nrargs=u16(rec+20)
    params=[]
    for a in range(nrargs):
        po=rec+0x18+a*12
        t=i32(po); noff=i32(po+4); fl=i32(po+8)
        pt,fmt=dtype(t)
        params.append({'name':name_at(noff),'flags':fl,'type':pt,'format':fmt})
    # Pascal-level: retval param -> result; others are args
    result=('Integer','int32 (0=fail / >0=ok, sau HRESULT)')
    args=[]
    for p in params:
        if p['flags'] & 8:      # FRETVAL
            result=(p['type'],p['format'])
        elif p['flags'] & 2:    # out
            args.append(('out',p['name'],p['type'],p['format']))
        else:
            args.append(('in',p['name'],p['type'],p['format']))
    out.append({'name':funcnames[fi] if fi<len(funcnames) else '?',
                'slot':vtbloff//4,'ret':result[0],'ret_fmt':result[1],'args':args})
    rec+=reclen

# emit markdown + json
json.dump(out, open('/tmp/mentorapi_methods.json','w'), ensure_ascii=False, indent=1)
def sig(m):
    a='; '.join('%s %s: %s'%('out' if d=='out' else 'const',n or 'p',t) for d,n,t,f in m['args'])
    return '%s(%s): %s'%(m['name'],a,m['ret'])
print("total metode:",len(out))
with open('/tmp/mentorapi_methods.md','w') as f:
    f.write("# DocImpServer — toate metodele (din .tlb oficial curent)\n\n")
    for m in out:
        f.write("### %s  _(vtable slot %d)_\n```pascal\n%s\n```\n"%(m['name'],m['slot'],sig(m)))
        if m['args']:
            f.write("| parametru | dir | tip | format |\n|---|---|---|---|\n")
            for d,n,t,fm in m['args']:
                f.write("| %s | %s | %s | %s |\n"%(n or '',d,t,fm))
        f.write("| **return** |  | %s | %s |\n\n"%(m['ret'],m['ret_fmt']))
print("scris /tmp/mentorapi_methods.md si .json")
print("\nprimele 15 semnaturi:")
for m in out[:15]: print("  slot%3d %s"%(m['slot'],sig(m)))
