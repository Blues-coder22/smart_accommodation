import re,glob,collections,colorsys
files=['assets/css/style.css','assets/css/a11y.css','assets/css/home.css','assets/css/attractions.css','assets/css/finance.css','assets/css/static-pages.css']
def hx(s):
    s=s.lstrip('#')
    if len(s)==3: s=''.join(c*2 for c in s)
    return tuple(int(s[i:i+2],16)/255 for i in (0,2,4))
def lum(c): 
    f=lambda v: v/12.92 if v<=.03928 else ((v+.055)/1.055)**2.4
    r,g,b=map(f,c); return .2126*r+.7152*g+.0722*b
def hue_kind(c):
    h,l,s=colorsys.rgb_to_hls(*c)
    if s<.12: return 'gray'
    h*=360
    if 70<=h<=170: return 'green'
    if h<=40 or h>=330: return 'red'   # terracotta/orange/red
    if 40<h<70: return 'amber'
    return 'gray'
SURF={'page':'#0f1512','s1':'#171f1b','s2':'#1e2823','s3':'#25312b'}
def bg_map(c):
    L=lum(c)
    k=hue_kind(c)
    if L>0.90:  return SURF['s1']
    if L>0.74:
        return {'green':'#1b2b24','red':'#2f211c','amber':'#2d2818','gray':SURF['s2']}[k]
    if L>0.55:
        return {'green':'#22362d','red':'#3a2620','amber':'#34301d','gray':SURF['s3']}[k]
    return None
def fg_map(c):
    L=lum(c); k=hue_kind(c)
    if L>0.55: return None
    if k=='green': return '#8fd3b4' if L<0.2 else '#7bc5a3'
    if k=='red':   return '#f3a88f' if L<0.25 else '#f0a58a'
    if k=='amber': return '#e6c36a'
    if L<0.12: return '#e8ece9'
    if L<0.30: return '#c4ccc7'
    return '#a9b3ad'
def bd_map(c):
    L=lum(c)
    if L>0.45: return '#34413a'
    return None
groups=collections.OrderedDict()
def add(sel,decl):
    groups.setdefault(decl,[]).append(sel)
import os
extra=[]
for dp,dn,fn in os.walk('.'):
    dn[:]=[d for d in dn if d not in('vendor','StayExplore_Kenya_Authenticated_Frontend','database','uploads','private_uploads')]
    for f in fn:
        if f.endswith(('.php','.html')):
            t=open(os.path.join(dp,f),encoding='utf-8',errors='ignore').read()
            t=re.sub(r'<\?(?:php|=).*?\?>',' ',t,flags=re.S)
            for m in re.finditer(r'<style[^>]*>(.*?)</style>',t,flags=re.S|re.I): extra.append(m.group(1))
sources=[('f',f) for f in files]+[('t',e) for e in extra]
for kind,val in sources:
    css=open(val,encoding='utf-8').read() if kind=='f' else val
    css=re.sub(r'/\*.*?\*/','',css,flags=re.S)
    css=re.sub(r'@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}','',css)
    for m in re.finditer(r'([^{}@][^{}]*)\{([^{}]*)\}',css):
        sels,body=m.group(1).strip(),m.group(2)
        if re.match(r'^(from|to|\d+%)',sels): continue
        out=[]
        for d in body.split(';'):
            if ':' not in d: continue
            p,v=d.split(':',1); p=p.strip().lower(); v=v.strip()
            vi=re.sub(r'!important','',v).strip()
            if p in('background','background-color'):
                if 'gradient' in vi or 'url(' in vi: 
                    # light gradients on cards -> flat dark surface
                    hs=re.findall(r'#[0-9a-fA-F]{3,8}\b',vi)
                    if 'url(' not in vi and hs and all(lum(hx(h[:7]))>0.7 for h in hs if len(h) in(4,7)):
                        out.append('background:%s!important'%SURF['s2'])
                    continue
                mm=re.fullmatch(r'#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}',vi.split()[0] if vi else '')
                if mm:
                    n=bg_map(hx(mm.group(0)))
                    if n: out.append('background-color:%s!important'%n)
                elif vi in('var(--paper)',): pass
            elif p=='color':
                if vi=='var(--forest)': out.append('color:#8fd3b4!important')
                else:
                    mm=re.fullmatch(r'#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}',vi)
                    if mm:
                        n=fg_map(hx(mm.group(0)))
                        if n: out.append('color:%s!important'%n)
            elif p.startswith('border') and p.endswith(('color','top','bottom','left','right','border')) or p in('border','border-top','border-bottom','border-left','border-right','outline'):
                hs=re.findall(r'#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b',vi)
                if hs and 'var(' not in vi:
                    n=bd_map(hx(hs[0]))
                    if n:
                        pp=p if p.endswith('color') else p+'-color'
                        out.append('%s:%s!important'%(pp,n))
        if out:
            decl=';'.join(sorted(set(out)))
            for s in sels.split(','):
                s=s.strip()
                if not s or s.startswith(('@',':root','html','body{')) or ':focus' in s or '::selection' in s: continue
                if re.fullmatch(r'[a-z0-9]+|\*|body|main',s): continue   # bare tags would leak from one page's <style> onto every page
                if re.search(r'(^|\s)(\.btn|button)(?![-\w])',s) and 'background' in decl and ('.btn:hover' in s): pass
                add(s,decl)
res=[]
for decl,sels in groups.items():
    uniq=list(dict.fromkeys(sels))
    res.append(',\n'.join('html[data-theme="dark"] '+s for s in uniq)+'{'+decl+'}')
open('/home/claude/dark_generated.css','w').write('\n'.join(res))
print(len(groups),'groups',sum(len(v) for v in groups.values()),'selectors')
