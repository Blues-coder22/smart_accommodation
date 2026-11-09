/* StayExplore Kenya — frontend-only auth, authorization and UI helpers.
   Loaded in <head> (blocking) so access checks run BEFORE the page is painted.
   NOTE: browser-only demo security. Real security needs a server (hashed passwords, sessions, role checks). */
(function () {
'use strict';
if (window.__smartAccommodationServerRendered) return;
if (/\.php$/i.test(window.location.pathname)) return;
if (window.__stayExploreLoaded) return;   // guards against the script being included twice on a page
window.__stayExploreLoaded = true;

/* ---------- constants ---------- */
const K = { acc:'stayexplore_accounts', ses:'stayexplore_session', next:'stayexplore_next',
            flash:'stayexplore_flash', draft:'stayexplore_draft', bookings:'stayexplore_bookings',
            last:'stayexplore_last_booking', favs:'stayexplore_favs_',
            listings:'stayexplore_listings', attractions:'stayexplore_attractions', allbk:'stayexplore_bookings_v2', reviews:'stayexplore_reviews', incidents:'stayexplore_incidents', threads:'stayexplore_threads', outbox:'stayexplore_outbox' };
const SESSION_HOURS = 24;
const ROLE_PAGES = {
  tourist:['book.html','payment.html','confirmation.html','bookings.html','review.html','favourites.html','report.html','profile.html','messages.html'],
  host:['host-dashboard.html','host-add-listing.html','host-edit-listing.html','host-manage-listings.html','host-availability.html','host-manage-bookings.html','host-payments.html','host-reviews.html','host-report-incident.html','host-profile.html','host-messages.html'],
  admin:['admin-dashboard.html','admin-listing-approvals.html','admin-manage-users.html','admin-moderate-reviews.html','admin-incident-reports.html','admin-attractions.html','admin-profile.html']
};
const OPEN_PAGES = ['index.html','search.html','details.html','attraction-details.html','how-it-works.html','about.html','contact.html','help.html','privacy.html','terms.html','login.html','register.html','confirm-email.html','mailbox.html'];
const STAYS = {
  'karura-house':{name:'Karura House',place:'Westlands, Nairobi',price:8400,rating:'4.8',cls:'user-house'},
  'high-rise':{name:'High Rise',place:'Kilimani, Nairobi',price:6200,rating:'4.6',cls:'user-mountain'},
  'fernside-retreat':{name:'Fernside Retreat',place:'Karen, Nairobi',price:9400,rating:'4.5',cls:'user-lamu'}
};

/* ---------- safe storage ---------- */
const ls = { get(k){try{return localStorage.getItem(k)}catch{return null}}, set(k,v){try{localStorage.setItem(k,v)}catch{}}, del(k){try{localStorage.removeItem(k)}catch{}} };
const ss = { get(k){try{return sessionStorage.getItem(k)}catch{return null}}, set(k,v){try{sessionStorage.setItem(k,v)}catch{}}, del(k){try{sessionStorage.removeItem(k)}catch{}} };
const readJSON = (store,k,fb)=>{ try{ const v=store.get(k); return v?JSON.parse(v):fb; }catch{ return fb; } };

/* ---------- accounts, hashing, session ---------- */
const getAccounts = ()=>readJSON(ls,K.acc,[]);
const saveAccounts = a=>putLS(K.acc,JSON.stringify(a));   // returns false when browser storage is full
async function sha(text){
  if(window.crypto && crypto.subtle){
    const buf=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(text));
    return [...new Uint8Array(buf)].map(b=>b.toString(16).padStart(2,'0')).join('');
  }
  let h=5381; for(const c of text) h=((h<<5)+h+c.charCodeAt(0))>>>0; return 'x'+h.toString(16); // fallback only
}
const newSalt = ()=>Math.random().toString(36).slice(2)+Date.now().toString(36);
async function ensureDemoAdmin(){
  const list=getAccounts();
  if(list.some(a=>a.id==='admin-system')) return;
  const salt='demo-admin-salt';
  list.push({id:'admin-system',name:'Njeri Mwangi',email:'admin@stayexplore.co.ke',role:'admin',salt,hash:await sha(salt+'Admin123!')});
  saveAccounts(list);
}
function getSession(){
  const s=readJSON(ls,K.ses,null);
  if(!s || !s.exp || s.exp<Date.now()){ if(s) ls.del(K.ses); return null; }
  return s;
}
function setSession(a){ ls.set(K.ses,JSON.stringify({id:a.id,name:a.name,email:a.email,role:a.role,exp:Date.now()+SESSION_HOURS*3600e3})); }
const roleLabel = r=>r==='admin'?'Admin':r==='host'?'Host':'Tourist';
const routeForRole = r=>r==='host'?'host-dashboard.html':r==='admin'?'admin-dashboard.html':'search.html';
const currentPage = ()=>location.pathname.split('/').pop() || 'index.html';
const setFlash = m=>ss.set(K.flash,m);
function consumeFlash(){ const m=ss.get(K.flash); if(m) ss.del(K.flash); return m; }
const esc = s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const go = url=>location.replace(url);

/* only same-site known pages the role may open — prevents open redirects and wrong-role loops */
function safeNext(role){
  let n=null; try{ const o=JSON.parse(ss.get(K.next)||'null'); if(o && Date.now()-o.t<10*60e3) n=o.p; }catch{} ss.del(K.next);
  if(!n || !/^[a-z0-9-]+\.html(\?[\w=&%.\-]*)?(#[\w-]*)?$/i.test(n)) return null;
  const file=n.split(/[?#]/)[0];
  return (ROLE_PAGES[role]||[]).includes(file) || OPEN_PAGES.includes(file) ? n : null;
}

/* ---------- access guard: runs immediately, before paint ---------- */
const root=document.documentElement;
const need=root.dataset.auth;               // tourist | host | admin | guest | (none = public)
root.classList.add('se-pending');
let redirecting=false;
function guard(){
  const s=getSession();
  if(s && getAccounts().some(a=>a.id===s.id && a.suspended)){ ls.del(K.ses); redirecting=true; setFlash('This account has been suspended.'); go('login.html'); return false; }
  if(need==='guest' && s){ redirecting=true; go(safeNext(s.role)||routeForRole(s.role)); return false; }
  if(['tourist','host','admin'].includes(need)){
    if(!s){ redirecting=true; ss.set(K.next,JSON.stringify({p:currentPage()+location.search+location.hash,t:Date.now()})); setFlash('Please sign in to continue.'); go('login.html'); return false; }
    if(s.role!==need){ redirecting=true; setFlash(`Access denied. That area is for ${roleLabel(need)} accounts only.`); go(routeForRole(s.role)); return false; }
  }
  return true;
}
guard();
const reveal=()=>{ if(!redirecting) root.classList.remove('se-pending'); };
setTimeout(reveal,2500);                                  // failsafe: never leave a blank page
window.addEventListener('pageshow',e=>{ if(e.persisted && guard()) reveal(); });   // back/forward cache
window.addEventListener('storage',e=>{ if(e.key===K.ses) { if(guard()) location.reload(); } }); // login/logout in another tab

function logout(){ ls.del(K.ses); ss.del(K.draft); setFlash('You have been signed out.'); go('index.html'); }
function requireLogin(msg){ ss.set(K.next,JSON.stringify({p:currentPage()+location.search+location.hash,t:Date.now()})); setFlash(msg||'Please sign in to continue.'); go('login.html'); }

/* ---------- toast ---------- */
function showToast(message){
  let t=document.querySelector('.toast');
  if(!t){ t=document.createElement('div'); t.className='toast'; t.setAttribute('role','status'); t.setAttribute('aria-live','polite'); document.body.appendChild(t); }
  t.textContent=message; t.classList.add('show');
  clearTimeout(window.__toast); window.__toast=setTimeout(()=>t.classList.remove('show'),3200);
}
window.showToast=showToast;

/* ---------- shared chrome: nav + footer ---------- */
function navLinks(s){
  const p=currentPage(), hash=location.hash;
  const items=[['index.html','Home'],['search.html','Discover'],['search.html#attractions','Attractions'],['how-it-works.html','How it works'],['about.html','About us']];
  if(s && s.role==='tourist'){ items.push(['bookings.html','My trips']); items.push(['favourites.html','Favourites']); items.push(['messages.html','Messages']); }
  return items.map(([h,l])=>{
    const f=h.split('#')[0], active = h.includes('#') ? (p===f && hash==='#attractions') : (p===f && hash!=='#attractions');
    return `<a href="${h}" ${active?'class="active" aria-current="page"':''}>${l}${h==='messages.html'?' <span class="msg-badge" data-msg-badge hidden></span>':''}</a>`;
  }).join('');
}
function accountActions(s,cls){
  if(!s) return `<a class="${cls==='hp'?'':'btn btn-outline'}" href="login.html">Sign in</a><a class="btn" href="register.html">Create account</a>`;
  const home=routeForRole(s.role);
  const label=s.role==='admin'?'Admin panel':s.role==='host'?'Host dashboard':'My account';
  const target=s.role==='tourist'?'profile.html':home;
  return `<span class="role-pill">${roleLabel(s.role)}</span><a class="${cls==='hp'?'':'btn btn-outline'}" href="${target}">${label}</a><button class="nav-signout" type="button" data-logout>Sign out</button>`;
}
function renderChrome(){
  const s=getSession();
  document.querySelectorAll('[data-site-nav]').forEach(h=>{
    h.innerHTML=`<a class="public-brand" href="index.html"><span class="brand-mark">SE</span><span>StayExplore<small>KENYA</small></span></a><button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false">☰</button><nav class="public-links" aria-label="Main">${navLinks(s)}</nav><div class="public-actions">${accountActions(s)}</div>`;
  });
  const hp=document.querySelector('.hp-header');
  if(hp){
    const nav=hp.querySelector('.hp-nav'), act=hp.querySelector('.hp-actions');
    if(nav) nav.innerHTML=`<a href="search.html">Discover</a><a href="how-it-works.html">How it works</a><a href="about.html">About us</a>`;
    if(act) act.innerHTML=s?accountActions(s,'hp'):`<a href="login.html">Sign in</a><a class="btn" href="register.html">Sign up</a>`;
    if(!hp.querySelector('.nav-toggle')) hp.insertAdjacentHTML('beforeend','<button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false">☰</button>');
  }
  document.querySelectorAll('.nav-toggle:not([data-bound])').forEach(b=>{ b.dataset.bound='1'; b.addEventListener('click',()=>{
    const h=b.closest('header'); const open=h.classList.toggle('nav-open'); b.setAttribute('aria-expanded',open);
  });});
  document.querySelectorAll('[data-site-footer]').forEach(f=>{
    f.innerHTML=`<div><div class="brand">StayExplore Kenya</div><p>Thoughtfully matching travellers with Kenyan stays and experiences.</p></div><div><h4>Explore</h4><a href="search.html">Stays</a><a href="search.html#attractions">Attractions</a><a href="how-it-works.html">How it works</a></div><div><h4>Company</h4><a href="about.html">About us</a><a href="contact.html">Contact</a><a href="register.html?role=host">List your property</a></div><div><h4>Support</h4><a href="help.html">Help centre</a><a href="privacy.html">Privacy</a><a href="terms.html">Terms</a></div>`;
  });
  document.querySelectorAll('[data-logout]:not([data-bound])').forEach(b=>{ b.dataset.bound='1'; b.addEventListener('click',e=>{e.preventDefault();logout();}); });
}

/* ======================================================================
   SHARED SITE DATA — listings & attractions
   One store (localStorage) read by tourist, host and admin screens, so a change
   made by a host or admin is what tourists actually see.
   ====================================================================== */
const ASSET_BASE = (window.APP_BASE_PATH || '/smart_accommodation') + '/assets/';
const IMG = {
  maraSunset:ASSET_BASE+'user-sunset.jpg', maraTent:ASSET_BASE+'mara-safari-tent.jpg', maraDeck:ASSET_BASE+'mara-river-deck.jpg', maraBoma:ASSET_BASE+'mara-boma-firepit.jpg',
  kenyaPeaks:ASSET_BASE+'user-mount-kenya.jpg', kenyaLodge:ASSET_BASE+'user-lodge.jpg', kenyaLake:ASSET_BASE+'kenya-lake-shore.jpg',
  lamuTown:ASSET_BASE+'user-lamu.jpg', coastBeach:ASSET_BASE+'user-beach.jpg',
  nairobiApt:ASSET_BASE+'user-house.jpg', karenCottage:ASSET_BASE+'karen-cottage-garden.jpg', nairobiDusk:ASSET_BASE+'nairobi-dusk-view.jpg'
};
/* region = landscape. Each region carries its own photos: dry savannah / forested highlands / sandy coast / city */
const REGIONS = {
  mara:{ name:'Maasai Mara', test:/narok|mara|maasai|talek|sekenani/i, images:[IMG.maraSunset,IMG.maraDeck,IMG.maraTent,IMG.maraBoma],
         nearby:[['Maasai Mara National Reserve gate','6 km'],['Mara River viewpoint','11 km'],['Maasai cultural village','8 km']] },
  highlands:{ name:'Mount Kenya', test:/nanyuki|laikipia|mt\.?\s*kenya|mount\s*kenya|nyeri|naro\s*moru|meru|timau/i, images:[IMG.kenyaPeaks,IMG.kenyaLodge,IMG.kenyaLake],
         nearby:[['Mount Kenya National Park gate','12 km'],['Sweetwaters Conservancy','28 km'],['Ol Pejeta Conservancy','35 km']] },
  coast:{ name:'the Kenyan coast', test:/lamu|shela|manda|diani|mombasa|malindi|watamu|kilifi|kwale|coast/i, images:[IMG.lamuTown,IMG.coastBeach],
         nearby:[['Lamu Old Town','1.2 km'],['Lamu Fort','1.0 km'],['Shela Beach','3.5 km']] },
  nairobi:{ name:'Nairobi', test:/nairobi|westlands|kilimani|karen|gigiri|lavington|runda|kileleshwa|langata/i, images:[IMG.nairobiApt,IMG.karenCottage,IMG.nairobiDusk],
         nearby:[['Karura Forest','2.1 km'],['Nairobi National Museum','3.2 km'],['Giraffe Centre','11.4 km']] }
};
const CATEGORY_REGION = { Wildlife:'mara', Mountain:'highlands', Nature:'highlands', Culture:'coast', Beach:'coast' };
const regionOf = text => { for(const [k,r] of Object.entries(REGIONS)) if(r.test.test(text||'')) return k; return null; };
const defaultImages = o => { const r = o.region || regionOf(`${o.location||''} ${o.county||''} ${o.name||''}`) || CATEGORY_REGION[o.category] || 'nairobi'; return REGIONS[r].images.slice(0,3); };
const coverOf = o => (o.images && o.images[0]) || defaultImages(o)[0];

const SEED_LISTINGS = [
  {id:'karura-house',name:'Karura House',location:'Westlands, Nairobi',region:'nairobi',price:8400,type:'Apartment',description:'A calm, design-led apartment close to Karura Forest, with crafted details and room to settle in.',amenities:['Wi-Fi','Breakfast','Parking','Air conditioning','Smart TV','24/7 support'],rules:'Check-in from 2pm. Quiet hours 10pm–7am.',rating:4.8,reviews:126,score:94,bookings:38,status:'approved',ownerId:'demo',hostName:'Wanjiku Kamau',submitted:'2026-10-05',images:[IMG.nairobiApt,IMG.karenCottage]},
  {id:'high-rise',name:'High Rise',location:'Kilimani, Nairobi',region:'nairobi',price:6200,type:'Apartment',description:'A bright upper-floor apartment with sunset views over the city, close to cafés, shops and the Ngong Road corridor.',amenities:['Wi-Fi','Parking','Air conditioning'],rules:'No parties. Check-out by 11am.',rating:4.6,reviews:25,score:89,bookings:19,status:'approved',ownerId:'demo',hostName:'Daniel Otieno',submitted:'2026-09-20',images:[IMG.nairobiDusk]},
  {id:'fernside-retreat',name:'Fernside Retreat',location:'Karen, Nairobi',region:'nairobi',price:9400,type:'Guest house',description:'A leafy cottage retreat in Karen with a wraparound garden, thatched roof and slow, unhurried mornings.',amenities:['Breakfast','Garden','Wi-Fi'],rules:'Quiet garden after 9pm.',rating:4.5,reviews:18,score:87,bookings:12,status:'approved',ownerId:'demo',hostName:'Grace Njoroge',submitted:'2026-09-12',images:[IMG.karenCottage,IMG.nairobiDusk]},
  {id:'mt-kenya-forest-lodge',name:'Mt Kenya Forest Lodge',location:'Nanyuki, Mt Kenya region',region:'highlands',price:8500,type:'Lodge',description:"Come relax in this beautiful lodge after a day of hiking. Wake up by the fireplace with a view of the peaks, ready for tomorrow's trail.",amenities:['Wi-Fi','Breakfast','Parking','Fireplace'],rules:'Early trail departures welcome — tell us the night before.',rating:4.7,reviews:26,score:91,bookings:26,status:'approved',ownerId:'demo',hostName:'Wanjiku Kamau',submitted:'2026-09-28',images:[IMG.kenyaLodge,IMG.kenyaPeaks,IMG.kenyaLake]},
  {id:'lamu-old-town-retreat',name:'Lamu Old Town Retreat',location:'Shela, Lamu',region:'coast',price:9800,type:'Guest house',description:'A whitewashed Swahili house on a car-free lane, steps from the sea. Wander the old town, sail at sunset and sleep to the sound of the waves.',amenities:['Wi-Fi','Breakfast','Sea view'],rules:'Respect local dress customs in the old town.',rating:4.9,reviews:31,score:92,bookings:22,status:'approved',ownerId:'demo',hostName:'Fatma Bakari',submitted:'2026-09-15',images:[IMG.lamuTown,IMG.coastBeach]},
  {id:'mara-savannah-camp',name:'Mara Savannah Camp',location:'Talek, Maasai Mara, Narok',region:'mara',price:18500,type:'Tented camp',description:'Canvas suites on the open savannah with guided game drives at dawn, a firepit boma for the evenings and big-cat country on your doorstep.',amenities:['Breakfast','Wi-Fi','Parking','Guided game drives'],rules:'Stay inside the camp after dark; an askari will escort you.',rating:4.9,reviews:54,score:90,bookings:41,status:'approved',ownerId:'demo',hostName:'Joseph Ole Sankale',submitted:'2026-09-02',images:[IMG.maraTent,IMG.maraBoma,IMG.maraDeck]},
  {id:'lamu-retreat',name:'Lamu Retreat',location:'Lamu',region:'coast',price:7800,type:'Guest house',description:'A simple, sunny guest house a short walk from the beach, with a shaded courtyard and sea breezes.',amenities:['Wi-Fi','Breakfast'],rules:'',rating:0,reviews:0,score:78,bookings:0,status:'pending',ownerId:'demo',hostName:'Ali Hassan',submitted:'2026-10-04',images:[IMG.coastBeach]},
  {id:'forest-view-lodge',name:'Forest View Lodge',location:'Nanyuki',region:'highlands',price:7200,type:'Lodge',description:'A quiet lodge on the edge of the forest with lake views, log fires and guided walks.',amenities:['Parking','Breakfast'],rules:'',rating:0,reviews:0,score:76,bookings:0,status:'review',ownerId:'demo',hostName:'Peter Maina',submitted:'2026-10-03',images:[IMG.kenyaLake]}
];
const SEED_ATTRACTIONS = [
  {id:'maasai-mara',name:'Maasai Mara',county:'Narok',category:'Wildlife',region:'mara',tagline:'Iconic savannah landscapes, wildlife and the Great Migration.',
   description:"Explore one of Kenya's most iconic wildlife landscapes: golden grassland dotted with flat-topped acacias, big-cat country and the seasonal river crossings of the Great Migration. Search nearby stays to build a practical trip around the attraction.",
   terrain:'Open, dry savannah with scattered acacia',bestTime:'July – October (Great Migration)',climate:'Warm, dry days and cool evenings',bestFor:'Wildlife, photography, guided experiences',
   images:[IMG.maraSunset,IMG.maraDeck,IMG.maraTent,IMG.maraBoma],status:'published'},
  {id:'mount-kenya',name:'Mount Kenya',county:'Laikipia',category:'Mountain',region:'highlands',tagline:'Alpine lakes, forest trails and dramatic mountain scenery.',
   description:"Africa's second-highest peak rises out of green montane forest, bamboo and moorland to jagged glaciated summits. Hike forest trails, spot rock hyrax and birdlife, and wake to mountain views from the lodges below.",
   terrain:'Lush montane forest, moorland and rocky peaks',bestTime:'January – February and August – September',climate:'Cool and misty; cold at altitude',bestFor:'Hiking, birdwatching, mountain scenery',
   images:[IMG.kenyaPeaks,IMG.kenyaLodge,IMG.kenyaLake],status:'published'},
  {id:'lamu-old-town',name:'Lamu Old Town',county:'Lamu',category:'Culture',region:'coast',tagline:'A historic Swahili town with dhow trips, beaches and quiet lanes.',
   description:"A UNESCO World Heritage town of carved wooden doors, whitewashed lanes and no cars — just donkeys, dhows and warm Indian Ocean water. Sail at sunset, then walk out to wide, powder-white beaches.",
   terrain:'Sandy beaches, coral-stone old town and palm-fringed coast',bestTime:'December – March and July – October',climate:'Hot and humid with cooling sea breezes',bestFor:'Culture, beaches, dhow trips, slow travel',
   images:[IMG.lamuTown,IMG.coastBeach],status:'published'}
];

/* Each sample listing belongs to one named sample host. These are real accounts (password Host123!) so you can sign in and manage them. */
const SAMPLE_PASSWORD='Host123!';
const SAMPLE_HOSTS=[
  {id:'host-wanjiku',name:'Wanjiku Kamau',email:'wanjiku@stayexplore.co.ke',phone:'+254 712 345 678',business:'Wanjiku Stays'},
  {id:'host-daniel',name:'Daniel Otieno',email:'daniel@stayexplore.co.ke',phone:'+254 722 456 789',business:'Kilimani Urban Stays'},
  {id:'host-grace',name:'Grace Njoroge',email:'grace@stayexplore.co.ke',phone:'+254 733 567 890',business:'Fernside Retreat Ltd'},
  {id:'host-fatma',name:'Fatma Bakari',email:'fatma@stayexplore.co.ke',phone:'+254 701 678 901',business:'Lamu Swahili Houses'},
  {id:'host-joseph',name:'Joseph Ole Sankale',email:'joseph@stayexplore.co.ke',phone:'+254 710 789 012',business:'Mara Savannah Camp'},
  {id:'host-ali',name:'Ali Hassan',email:'ali@stayexplore.co.ke',phone:'+254 720 890 123',business:'Lamu Retreat'},
  {id:'host-peter',name:'Peter Maina',email:'peter@stayexplore.co.ke',phone:'+254 730 901 234',business:'Forest View Lodge'}
];
const SAMPLE_OWNER={'karura-house':'host-wanjiku','mt-kenya-forest-lodge':'host-wanjiku','high-rise':'host-daniel','fernside-retreat':'host-grace','lamu-old-town-retreat':'host-fatma','mara-savannah-camp':'host-joseph','lamu-retreat':'host-ali','forest-view-lodge':'host-peter','diani-sands-villa':'host-fatma','naivasha-lake-lodge':'host-peter'};
SEED_LISTINGS.push(
  {id:'diani-sands-villa',name:'Diani Sands Villa',location:'Diani Beach, Kwale',region:'coast',price:12500,type:'Villa',description:'A breezy beach villa a short walk from the white sands of Diani, with a shaded veranda, garden and easy access to the reef and restaurants.',amenities:['Wi-Fi','Breakfast','Parking','Sea view'],rules:'No loud music after 10pm.',rating:4.7,reviews:22,score:88,bookings:17,status:'approved',hostName:'Fatma Bakari',submitted:'2026-09-08',images:[IMG.coastBeach,IMG.lamuTown]},
  {id:'naivasha-lake-lodge',name:'Naivasha Lake Lodge',location:'Lake Naivasha, Nakuru',region:'highlands',price:10800,type:'Lodge',description:'A quiet lakeside lodge with acacia-shaded lawns, birdlife at the water and easy day trips to Hell\'s Gate and the Rift Valley.',amenities:['Wi-Fi','Breakfast','Parking','Garden'],rules:'Please keep to the paths after dark; hippos graze on the lawns.',rating:4.6,reviews:19,score:86,bookings:14,status:'approved',hostName:'Peter Maina',submitted:'2026-09-05',images:[IMG.kenyaLake,IMG.karenCottage]}
);
const SEED_COORDS={'karura-house':[-1.2676,36.8108],'high-rise':[-1.2921,36.7872],'fernside-retreat':[-1.3226,36.7085],'mt-kenya-forest-lodge':[-0.0420,37.1100],'lamu-old-town-retreat':[-2.2790,40.9160],'mara-savannah-camp':[-1.4700,35.1450],'lamu-retreat':[-2.2717,40.9025],'forest-view-lodge':[0.0050,37.0300],'diani-sands-villa':[-4.3000,39.5900],'naivasha-lake-lodge':[-0.7700,36.4000]};
SEED_LISTINGS.forEach(l=>{ l.ownerId=SAMPLE_OWNER[l.id]||l.ownerId; const c=SEED_COORDS[l.id]; if(c){ l.lat=c[0]; l.lng=c[1]; l.coordsSource='pinned'; } });
const ATTR_COORDS={'maasai-mara':[-1.4900,35.1400],'mount-kenya':[-0.1521,37.3084],'lamu-old-town':[-2.2696,40.9006]};
SEED_ATTRACTIONS.forEach(a=>{ const c=ATTR_COORDS[a.id]; if(c){ a.lat=c[0]; a.lng=c[1]; a.featured=true; a.coordsSource='pinned'; } });
SEED_ATTRACTIONS.push(
  {id:'amboseli-national-park',name:'Amboseli National Park',county:'Kajiado',category:'Wildlife',region:null,lat:-2.6527,lng:37.2606,coordsSource:'pinned',tagline:'Big elephant herds beneath the snows of Kilimanjaro.',description:'Flat, dusty plains and swamps where huge elephant herds walk with Mount Kilimanjaro rising across the border. One of the best places in Africa to photograph elephants up close.',terrain:'Open, dry plains with swamps and acacia',bestTime:'June – October (dry season)',climate:'Hot, dry and dusty',bestFor:'Elephants, photography, game drives',images:[IMG.maraSunset,IMG.maraDeck],status:'published'},
  {id:'lake-nakuru-national-park',name:'Lake Nakuru National Park',county:'Nakuru',category:'Wildlife',region:null,lat:-0.3667,lng:36.0833,coordsSource:'pinned',tagline:'A soda lake ringed by woodland, rhinos and birdlife.',description:'A compact park around a shallow soda lake, famous for flamingos and for both black and white rhino. Easy to explore in a day from Nakuru town.',terrain:'Lakeshore, grassland and acacia woodland',bestTime:'June – March',climate:'Warm days, cool evenings',bestFor:'Rhinos, birdwatching, short trips',images:[IMG.kenyaLake,IMG.maraDeck],status:'published'},
  {id:'hells-gate-naivasha',name:"Lake Naivasha & Hell's Gate",county:'Nakuru',category:'Nature',region:null,lat:-0.8800,lng:36.3200,coordsSource:'pinned',tagline:'Cycle between cliffs and geysers beside a freshwater Rift Valley lake.',description:"A freshwater lake with hippos and birdlife, and next door the gorge and cliffs of Hell's Gate, where you can walk or cycle among zebra and giraffe.",terrain:'Freshwater lake, cliffs and open grassland',bestTime:'June – October and January – February',climate:'Warm days, cool nights',bestFor:'Cycling, boat trips, hiking',images:[IMG.kenyaLake,IMG.karenCottage],status:'published'},
  {id:'nairobi-national-park',name:'Nairobi National Park',county:'Nairobi',category:'Wildlife',region:null,lat:-1.3733,lng:36.8588,coordsSource:'pinned',tagline:'Lions and giraffes with the city skyline behind them.',description:'The only national park inside a capital city: savannah, rhino and lion just minutes from the city centre. Perfect for a half-day game drive.',terrain:'Open savannah and forest patches beside the city',bestTime:'Year-round (best June – October)',climate:'Mild, with a dry and a wet season',bestFor:'Short game drives, families',images:[IMG.maraSunset,IMG.nairobiDusk],status:'published'},
  {id:'karura-forest',name:'Karura Forest',county:'Nairobi',category:'Nature',region:null,lat:-1.2370,lng:36.8350,coordsSource:'pinned',tagline:'Waterfalls, caves and shaded trails in the middle of Nairobi.',description:'One of the largest urban forests in the world, with walking and cycling trails, a waterfall, a cave and plenty of birdlife. A calm start or end to any Nairobi trip.',terrain:'Indigenous forest with rivers and trails',bestTime:'Year-round',climate:'Cool and shaded',bestFor:'Walking, cycling, picnics',images:[IMG.kenyaLodge,IMG.karenCottage],status:'published'},
  {id:'diani-beach',name:'Diani Beach',county:'Kwale',category:'Beach',region:null,lat:-4.3200,lng:39.5800,coordsSource:'pinned',tagline:'Miles of powder-white sand and turquoise water.',description:'A long white beach backed by palms and coral reef. Swim, snorkel, kite-surf or simply do nothing for a few days.',terrain:'White sandy beach, coral reef and palms',bestTime:'December – March and July – October',climate:'Hot and humid with sea breezes',bestFor:'Beach holidays, snorkelling, kite-surfing',images:[IMG.coastBeach,IMG.lamuTown],status:'published'},
  {id:'fort-jesus-mombasa',name:'Fort Jesus, Mombasa',county:'Mombasa',category:'Culture',region:null,lat:-4.0626,lng:39.6797,coordsSource:'pinned',tagline:'A 16th-century Portuguese fort on the Mombasa waterfront.',description:'A UNESCO World Heritage fort built by the Portuguese in 1593, overlooking the old harbour. Pair it with a walk through Mombasa Old Town.',terrain:'Coral-stone fort on the harbour',bestTime:'Year-round',climate:'Hot and humid',bestFor:'History, culture, city walks',images:[IMG.lamuTown,IMG.coastBeach],status:'published'},
  {id:'watamu-marine-park',name:'Watamu Marine National Park',county:'Kilifi',category:'Beach',region:null,lat:-3.3500,lng:40.0300,coordsSource:'pinned',tagline:'Coral gardens, turtles and quiet white beaches.',description:'A protected marine park with coral gardens, tidal pools and turtle conservation, beside quiet beaches and the Gede ruins.',terrain:'Coral reef, tidal pools and white beaches',bestTime:'October – March',climate:'Hot and humid',bestFor:'Snorkelling, turtles, relaxing',images:[IMG.coastBeach],status:'published'}
);
const SAMPLE_TOURIST={id:'tourist-zawadi',name:'Zawadi Mwangi',email:'tourist@stayexplore.co.ke',phone:'+254 700 123 456'};
async function ensureSampleHosts(){
  const list=getAccounts(); let changed=false;
  if(!list.some(a=>a.id===SAMPLE_TOURIST.id)){ const salt='sample-'+SAMPLE_TOURIST.id; list.push({id:SAMPLE_TOURIST.id,created:Date.parse('2026-09-01'),name:SAMPLE_TOURIST.name,email:SAMPLE_TOURIST.email,role:'tourist',phone:SAMPLE_TOURIST.phone,country:'Kenya',sample:true,salt,hash:await sha(salt+'Tourist123!')}); changed=true; }
  for(const h of SAMPLE_HOSTS){
    if(list.some(a=>a.id===h.id)) continue;
    const salt='sample-'+h.id;
    list.push({id:h.id,created:Date.parse('2026-09-01'),name:h.name,email:h.email,role:'host',phone:h.phone,business:h.business,sample:true,salt,hash:await sha(salt+SAMPLE_PASSWORD)}); changed=true;
  }
  if(changed) saveAccounts(list);
}

/* ---------- store helpers ---------- */
const putLS = (k,v)=>{ try{ localStorage.setItem(k,v); return true; }catch{ return false; } };   // returns false when storage is full
function seedStore(){
  if(!ls.get(K.listings)) putLS(K.listings,JSON.stringify(SEED_LISTINGS));
  if(!ls.get(K.attractions)) putLS(K.attractions,JSON.stringify(SEED_ATTRACTIONS));
  if(ls.get('stayexplore_data_v')!=='4') migrateData();
}
/* v2: sample listings are manageable by any host (so bookings on them are visible), and old per-guest bookings move to the shared list */
function migrateData(){
  const L=readJSON(ls,K.listings,[]); L.forEach(l=>{ if(l.ownerId==='seed') l.ownerId='demo'; }); putLS(K.listings,JSON.stringify(L));
  const legacy=readJSON(ls,K.bookings,null);
  if(legacy && !Array.isArray(legacy)){
    const all=readJSON(ls,K.allbk,[]), accs=getAccounts();
    Object.entries(legacy).forEach(([uid,list])=>(list||[]).forEach(b=>{
      const l=L.find(x=>x.name===b.stay), a=accs.find(x=>x.id===uid);
      all.push(Object.assign({stayId:l?l.id:'karura-house',hostId:l?l.ownerId:'demo',guestId:uid,guestName:a?a.name:'Guest',guestEmail:a?a.email:'',status:'confirmed',payment:'paid',fee:Math.round((b.total||0)/1.05*.05),nights:nightsBetween(b.checkin,b.checkout)},b));
    }));
    putLS(K.allbk,JSON.stringify(all)); ls.del(K.bookings);
  }
  /* v3: every sample listing (and booking on it) now belongs to its own sample host */
  const L2=readJSON(ls,K.listings,[]); L2.forEach(l=>{ if((l.ownerId==='demo'||l.ownerId==='seed') && SAMPLE_OWNER[l.id]) l.ownerId=SAMPLE_OWNER[l.id]; }); putLS(K.listings,JSON.stringify(L2));
  const B2=readJSON(ls,K.allbk,[]); B2.forEach(b=>{ const l=L2.find(x=>x.id===b.stayId); if(l) b.hostId=l.ownerId; }); putLS(K.allbk,JSON.stringify(B2));
  /* v4: map coordinates, new sample attractions and stays */
  const L3=readJSON(ls,K.listings,[]);
  SEED_LISTINGS.forEach(sl=>{ const e=L3.find(x=>x.id===sl.id); if(!e) L3.push(JSON.parse(JSON.stringify(sl))); else if(!hasGeo(e)&&hasGeo(sl)){ e.lat=sl.lat; e.lng=sl.lng; e.coordsSource='pinned'; } });
  L3.forEach(l=>{ if(!hasGeo(l)){ const p=placeFor(l.location+' '+l.name); if(p){ l.lat=p.lat; l.lng=p.lng; l.coordsSource='approx'; } } });
  putLS(K.listings,JSON.stringify(L3));
  const A3=readJSON(ls,K.attractions,[]);
  SEED_ATTRACTIONS.forEach(sa=>{ const e=A3.find(x=>x.id===sa.id); if(!e) A3.push(JSON.parse(JSON.stringify(sa))); else { if(!hasGeo(e)&&hasGeo(sa)){ e.lat=sa.lat; e.lng=sa.lng; e.coordsSource='pinned'; } if(sa.featured) e.featured=true; } });
  A3.forEach(a=>{ if(!hasGeo(a)){ const p=placeFor(a.county+' '+a.name); if(p){ a.lat=p.lat; a.lng=p.lng; a.coordsSource='approx'; } } });
  putLS(K.attractions,JSON.stringify(A3));
  ls.set('stayexplore_data_v','4');
}
const getListings = ()=>{ seedStore(); const v=readJSON(ls,K.listings,null); return Array.isArray(v)?v:JSON.parse(JSON.stringify(SEED_LISTINGS)); };
const saveListings = a=>putLS(K.listings,JSON.stringify(a));
const getAttractions = ()=>{ seedStore(); const v=readJSON(ls,K.attractions,null); return Array.isArray(v)?v:JSON.parse(JSON.stringify(SEED_ATTRACTIONS)); };
const saveAttractions = a=>putLS(K.attractions,JSON.stringify(a));
const listingById = id=>getListings().find(l=>l.id===id);
const attractionById = id=>getAttractions().find(a=>a.id===id);
const nearbyStays = (a,r=100)=>hasGeo(a)?withDist(approvedStays(),a).filter(x=>x.d<=r).map(x=>Object.assign({},x.o,{_km:x.d})):getListings().filter(l=>l.status==='approved' && (a.region ? l.region===a.region : (a.county && (l.location||'').toLowerCase().includes(a.county.toLowerCase()))));
const canEditListing = l=>{ const s=getSession(); return !!(s && l && s.role==='host' && l.ownerId===s.id); };

const slug = s=>String(s).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,40)||'item';
const uniqueId = (base,taken)=>{ let id=slug(base), n=2; while(taken.includes(id)) id=slug(base)+'-'+(n++); return id; };
const splitList = t=>String(t||'').split(/[,\n]/).map(x=>x.trim()).filter(Boolean);
const AMEN_KEYS = { wifi:/wi-?fi/i, breakfast:/breakfast/i, parking:/parking/i, garden:/garden/i };
const amenSlugs = list=>Object.entries(AMEN_KEYS).filter(([,re])=>(list||[]).some(x=>re.test(x))).map(([k])=>k);
const AMEN_ICON = a=>({'wi-fi':'Wi','breakfast':'Bk','free parking':'Pk','parking':'Pk','air conditioning':'AC','smart tv':'TV','24/7 support':'24','garden':'Gd','fireplace':'Fp','sea view':'Sv','guided game drives':'GD'}[a.toLowerCase()] || a.replace(/[^A-Za-z0-9]/g,'').slice(0,2) || '•');
const STATUS = { approved:['success','Approved'], pending:['warning','Pending'], review:['danger','Needs review'], rejected:['danger','Rejected'], suspended:['danger','Suspended'], published:['success','Published'], draft:['warning','Draft'] };
const badge = st=>{ const [c,l]=STATUS[st]||['info',st]; return `<span class="status ${c}">${l}</span>`; };
const bgStyle = src=>`background-image:url('${esc(src)}')`;
const stars = r=>{ const n=Math.round(r); return '★'.repeat(n)+'☆'.repeat(5-n); };
const shortDate = d=>{ const t=new Date(d+'T00:00:00'); return isNaN(t)?esc(d):t.toLocaleDateString('en-GB',{day:'2-digit',month:'short'}); };
const todayISO = ()=>new Date().toISOString().slice(0,10);
const QUOTA_MSG = 'Your browser has run out of storage for this demo. Try fewer or smaller photos.';

/* downscale an uploaded photo so it fits in browser storage */
function readImage(file,max=1200,quality=.78){
  return new Promise((resolve,reject)=>{
    if(!file || !/^image\//.test(file.type)) return reject(new Error('Please choose an image file (JPG or PNG).'));
    const r=new FileReader();
    r.onerror=()=>reject(new Error('That file could not be read.'));
    r.onload=()=>{
      const img=new Image();
      img.onerror=()=>reject(new Error('That image could not be opened.'));
      img.onload=()=>{
        const k=Math.min(1,max/Math.max(img.width,img.height)), c=document.createElement('canvas');
        c.width=Math.max(1,Math.round(img.width*k)); c.height=Math.max(1,Math.round(img.height*k));
        c.getContext('2d').drawImage(img,0,0,c.width,c.height);
        resolve(c.toDataURL('image/jpeg',quality));
      };
      img.src=r.result;
    };
    r.readAsDataURL(file);
  });
}
async function readImages(form,limit=4){
  const files=[...form.elements].filter(e=>e.type==='file').flatMap(i=>[...(i.files||[])]).slice(0,limit);   // form.elements also includes inputs linked with form="…"
  return Promise.all(files.map(f=>readImage(f)));
}
function bindPreviews(){
  document.querySelectorAll('input[type=file][data-preview]').forEach(i=>{
    if(i.dataset.pv) return; i.dataset.pv='1';
    i.addEventListener('change',()=>{
      const t=document.querySelector(i.dataset.preview), f=i.files&&i.files[0]; if(!t||!f) return;
      if(!/^image\//.test(f.type)){ showToast('Please choose an image file.'); i.value=''; return; }
      t.style.backgroundImage=`url('${URL.createObjectURL(f)}')`; t.style.backgroundSize='cover'; t.style.backgroundPosition='center'; t.textContent='';
      showToast(i.files.length>1?`${i.files.length} photos selected. Save to keep them.`:'Photo selected. Save to keep it.');
    });
  });
}
const setStat = (k,v)=>document.querySelectorAll(`[data-stat="${k}"]`).forEach(e=>e.textContent=v);
function monthlySeries(records,dateOf,valueOf){
  const now=new Date(), months=Array.from({length:6},(_,i)=>new Date(now.getFullYear(),now.getMonth()-5+i,1)), totals=months.map(()=>0);
  const indexes=new Map(months.map((d,i)=>[`${d.getFullYear()}-${d.getMonth()}`,i]));
  records.forEach(record=>{
    const timestamp=Number(dateOf(record));
    if(!Number.isFinite(timestamp)||timestamp<=0) return;
    const date=new Date(timestamp), index=indexes.get(`${date.getFullYear()}-${date.getMonth()}`);
    if(index===undefined) return;
    const value=Number(valueOf(record));
    if(Number.isFinite(value)) totals[index]+=value;
  });
  return {months,totals};
}
function drawMonthlyTrend(root,series,description,valueLabel){
  if(!root) return;
  const width=500, xStart=42, xEnd=490, yTop=24, yBottom=154, max=Math.max(0,...series.totals), scale=max||1;
  const xAt=i=>xStart+(xEnd-xStart)*i/Math.max(1,series.totals.length-1);
  const yAt=value=>yBottom-(value/scale)*(yBottom-yTop);
  const points=series.totals.map((value,i)=>`${xAt(i)},${yAt(value)}`).join(' ');
  const labels=series.months.map((date,i)=>`<text x="${xAt(i)}" y="183" text-anchor="middle" fill="#565c55" font-size="11">${date.toLocaleDateString('en-KE',{month:'short',year:'2-digit'})}</text>`).join('');
  const circles=series.totals.map((value,i)=>`<circle cx="${xAt(i)}" cy="${yAt(value)}" r="4" fill="#0d3b2e"><title>${series.months[i].toLocaleDateString('en-KE',{month:'long',year:'numeric'})}: ${valueLabel(value)}</title></circle>`).join('');
  const grid=[24,89,154].map(y=>`<line x1="${xStart}" y1="${y}" x2="${xEnd}" y2="${y}" stroke="#d9d5ca" stroke-width="1"/>`).join('');
  root.innerHTML=`<svg viewBox="0 0 ${width} 200" role="img" aria-label="${description}" preserveAspectRatio="none"><title>${description}</title>${grid}<polyline points="${points}" fill="none" stroke="#0d3b2e" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>${circles}${labels}</svg>`;
}

/* ---------- tourist: stay list, stay page ---------- */
function smartScore(l,ctx){
  const r=+l.rating||0, prices=approvedStays().map(x=>+x.price).sort((a,b)=>a-b), med=prices.length?prices[Math.floor(prices.length/2)]:(+l.price||1);
  const rating=r?r/5*100:60, popularity=Math.min(100,25+(+l.bookings||0)*2), value=Math.max(30,Math.min(100,100-((+l.price/med)-1)*50));
  let km=null; if(hasGeo(l)){ if(ctx) km=distKm(ctx,{lat:+l.lat,lng:+l.lng}); else { const n=nearbyAttractions(l,300)[0]; km=n?n._km:null; } }
  const proximity=km===null?50:Math.max(20,Math.min(100,100-(km-10)*.9));
  return {score:Math.round(.40*rating+.25*popularity+.20*value+.15*proximity),km,parts:{rating:Math.round(rating),popularity:Math.round(popularity),value:Math.round(value),proximity:Math.round(proximity)}};
}
function stayCardHTML(l,near){
  const r=+l.rating||0, amen=(l.amenities||[]).slice(0,3).join(' · '), km=near&&hasGeo(l)?distKm(near,{lat:+l.lat,lng:+l.lng}):null, sc=smartScore(l,near);
  return `<article class="card pad list-card filterable" data-price="${+l.price}" data-rating="${r}" data-score="${sc.score}" data-region="${esc(l.region||'')}" data-id="${esc(l.id)}" data-lat="${hasGeo(l)?+l.lat:''}" data-lng="${hasGeo(l)?+l.lng:''}" data-dist="${km===null?'':km.toFixed(1)}" data-amenities="${amenSlugs(l.amenities).join(',')}"><div class="photo" style="${bgStyle(coverOf(l))}"></div><div><div class="eyebrow">Smart score ${sc.score}</div><h3>${esc(l.name)}</h3><div class="rating">${r?`${stars(r)} <span class="small">${r.toFixed(1)} · ${l.reviews||0} reviews</span>`:'<span class="small">New listing · no reviews yet</span>'}</div><p class="small">${esc(l.location)}${amen?' · '+esc(amen):''}${ownerVerified(l)?' · <span class="vh">✓ Verified host</span>':''}</p>${km!==null?`<p class="small" style="color:var(--forest);font-weight:700">◉ ${fmtKm(km)} away</p>`:''}</div><div><div class="price">KES ${Math.round(l.price).toLocaleString('en-KE')}<small>/night</small></div><a class="btn btn-sm" href="details.html?id=${encodeURIComponent(l.id)}">View stay</a></div></article>`;
}
function attractionCardHTML(a){
  return `<article class="card"><div class="photo" style="${bgStyle(coverOf(a))}"></div><div class="pad"><div class="eyebrow">${esc(a.category)} · ${esc(a.county)}</div><h3>${esc(a.name)}</h3><p class="small">${esc(a.tagline||'')}</p>${a._km!==undefined?`<p class="small" style="color:var(--forest);font-weight:700">◉ ${fmtKm(a._km)} away</p>`:''}<a class="btn btn-sm" href="attraction-details.html?id=${encodeURIComponent(a.id)}">Explore attraction</a></div></article>`;
}
function initAttractionList(){
  const box=document.querySelector('#attractionList'); if(!box) return;
  const pr=new URLSearchParams(location.search), nm=(pr.get('near')||'').split(',').map(Number), near=nm.length===2&&nm.every(Number.isFinite)?{lat:nm[0],lng:nm[1],label:pr.get('label')||'this place',r:Math.min(500,Math.max(5,+pr.get('r')||100))}:null;
  let items=getAttractions().filter(a=>a.status==='published');
  if(near){ items=withDist(items.filter(hasGeo),near).filter(x=>x.d<=near.r).map(x=>Object.assign({},x.o,{_km:x.d})); const n=document.createElement('div'); n.className='notice region-note'; n.style.marginBottom='14px'; n.innerHTML=`Showing attractions within <strong>${near.r} km</strong> of <strong>${esc(near.label)}</strong>, nearest first. <a class="home-link" href="search.html#attractions">Show all attractions</a>`; box.parentElement.insertBefore(n,box); }
  box.innerHTML=items.length?items.map(attractionCardHTML).join(''):`<div class="card pad full empty"><h3>No attractions published yet.</h3><p class="small">Please check back soon.</p></div>`;
}
let currentStay=null;
function initStayPage(){
  const root=document.querySelector('[data-stay-page]'); if(!root) return;
  const id=new URLSearchParams(location.search).get('id')||'karura-house', l=listingById(id), s=getSession();
  const preview=!!(s && l && (s.role==='admin' || canEditListing(l)));
  if(!l || (l.status!=='approved' && !preview)){
    document.title='Stay not available — StayExplore Kenya';
    root.innerHTML=`<section class="page"><div class="eyebrow">Stay</div><h1>This stay isn't available.</h1><p class="sub">It may have been removed, or it is waiting for approval.</p><div class="actions" style="margin-top:20px"><a class="btn" href="search.html">Browse stays</a></div></section>`; return;
  }
  currentStay=l; document.title=`${l.name} — StayExplore Kenya`;
  const imgs=(l.images&&l.images.length?l.images:defaultImages(l)), r=+l.rating||0, reg=REGIONS[l.region]||REGIONS[regionOf(l.location)]||null;
  const gallery=imgs.length===1
    ? `<div class="photo-row wrap" style="grid-template-columns:1fr"><div class="photo" style="${bgStyle(imgs[0])}"></div></div>`
    : `<div class="photo-row wrap"><div class="photo" style="${bgStyle(imgs[0])}"></div><div class="photo-stack">${imgs.slice(1,3).map(i=>`<div class="photo" style="${bgStyle(i)}"></div>`).join('')}</div></div>`;
  const bars=[['Cleanliness',.1],['Location',0],['Comfort',-.1],['Value',-.2]].map(([n,d])=>{ const v=Math.min(5,Math.max(1,r+d)); return `<div class="rating-row"><span>${n}</span><div class="rating-track"><span style="width:${(v/5*100).toFixed(0)}%"></span></div><strong>${v.toFixed(1)}</strong></div>`; }).join('');
  const ratingCard=r?`<div class="card pad rating-summary"><div class="detail-card-heading"><div><div class="eyebrow">Guest rating</div><h2>${r.toFixed(1)}</h2></div><div class="rating-stars" aria-label="${r.toFixed(1)} out of 5 stars">${stars(r)}</div></div><p class="small">${r>=4.7?'Excellent':'Very good'} · ${l.reviews||0} verified reviews</p><div class="rating-bars">${bars}</div></div>`
    :`<div class="card pad rating-summary"><div class="eyebrow">Guest rating</div><h2>New</h2><p class="small">This is a new listing. Verified guest reviews will appear after the first stays.</p></div>`;
  const amen=(l.amenities||[]).map(a=>`<div class="amenity-item"><span class="amenity-icon">${esc(AMEN_ICON(a))}</span><div><strong>${esc(a)}</strong><small>Included</small></div></div>`).join('')||'<p class="small">The host has not listed amenities yet.</p>';
  const na=nearbyAttractions(l,150).slice(0,5);
  const nearby=hasGeo(l)?`<div class="card pad" style="margin-top:20px"><div class="detail-card-heading"><h2>Nearby attractions</h2>${na.length?`<a class="home-link" href="${nearHref(l,l.name,'attractions')}">See all →</a>`:''}</div><div class="detail-map" id="stayMap" aria-label="Map showing ${esc(l.name)} and nearby attractions"></div>${na.length?`<div class="grid grid-3" style="margin-top:12px">${na.slice(0,3).map(a=>`<a class="near-item" href="attraction-details.html?id=${encodeURIComponent(a.id)}"><span class="near-dot attraction"></span><span><strong>${esc(a.name)}</strong><span class="small">${esc(a.category)}</span></span><span class="near-km">${fmtKm(a._km)}</span></a>`).join('')}</div>`:'<p class="small" style="margin-top:10px">No published attractions within 150 km yet.</p>'}</div>`:(reg?`<div class="card pad" style="margin-top:20px"><h2>Nearby attractions</h2><div class="grid grid-3" style="margin-top:12px">${reg.nearby.map(([n,d])=>`<div><strong>${n}</strong><div class="small">${d}</div></div>`).join('')}</div></div>`:'');
  const pr=publicReviews(l.id);
  const reviews=pr.length?`<div class="card pad" style="margin-top:20px"><h2>Guest reviews</h2><div class="timeline">${pr.slice(0,6).map(x=>`<div><div class="rating">${stars(x.rating)} <span class="status success" style="font-size:10px">${x.seed?'Guest':'Verified stay'}</span></div><strong>${esc(firstName(x.authorName))}</strong> <span class="small">· ${longDate(x.created)}</span><p class="small">${esc(x.text)}</p></div>`).join('')}</div></div>`:`<div class="card pad" style="margin-top:20px"><h2>Guest reviews</h2><p class="small">No reviews yet. Reviews come only from guests with a completed booking.</p></div>`;
  window.__stayMapJob=()=>{ const el=document.querySelector('#stayMap'); if(el) mountNearMap(el,Object.assign(stayPin(l),{}),na.map(attrPin)); };
  const banner=l.status!=='approved'?`<div class="notice" style="margin-bottom:16px"><strong>Preview.</strong> This listing is “${esc((STATUS[l.status]||[,l.status])[1])}” and is not visible to tourists yet.</div>`:'';
  root.innerHTML=`${gallery}<section class="page" style="padding-top:30px"><div class="grid detail-layout"><div>${banner}<div class="eyebrow">${esc(l.type||'Stay')}</div><h1>${esc(l.name)}</h1><p class="rating">${esc((l.location||'').toUpperCase())}${r?` · ${r.toFixed(1)} (${l.reviews||0} REVIEWS)`:' · NEW LISTING'}</p>${ownerVerified(l)?'<p class="vh-line">✓ Verified host</p>':''}<p class="sub" style="margin-top:18px">${esc(l.description)}</p><section class="detail-info-grid" aria-label="Stay ratings and amenities">${ratingCard}<div class="card pad amenities-card"><div class="eyebrow">What this stay offers</div><h2>Amenities</h2><div class="amenities-grid">${amen}</div></div></section>${l.rules?`<div class="card pad" style="margin-top:20px"><h2>House rules</h2><p class="small" style="font-size:13px">${esc(l.rules)}</p></div>`:''}${nearby}${reviews}</div><aside><div class="card pad" style="position:sticky;top:95px"><div class="price">KSh ${Math.round(l.price).toLocaleString('en-KE')} <small>/ night</small></div><div class="grid grid-2" style="margin-top:18px"><div class="field"><label>CHECK IN</label><input type="date" value="2026-10-14" data-draft="checkin" required></div><div class="field"><label>CHECK OUT</label><input type="date" value="2026-10-18" data-draft="checkout" required></div></div><div class="field" style="margin-top:14px"><label>GUESTS</label><select data-draft="guests"><option>2 guests</option><option>1 guest</option><option>3 guests</option></select></div><a class="btn" style="display:flex;margin-top:16px" href="book.html" data-reserve>Reserve</a>${s&&s.role!=='tourist'?'':`<a class="btn btn-outline" style="display:flex;margin-top:10px" href="messages.html?stay=${esc(l.id)}">Message host</a>`}<a class="home-link" style="display:block;text-align:center;margin-top:13px" href="favourites.html" data-fav="${esc(l.id)}" data-label="♡ Save to favourites">♡ Save to favourites</a><a class="home-link" style="display:block;text-align:center;margin-top:9px" href="report.html?stay=${esc(l.id)}">Report this listing</a></div></aside></div></section>`;
  window.__stayMapJob&&window.__stayMapJob();
}

/* ---------- tourist: attraction page ---------- */
function initAttractionPage(){
  const root=document.querySelector('[data-attraction-page]'); if(!root) return;
  const id=new URLSearchParams(location.search).get('id')||'maasai-mara', a=attractionById(id), s=getSession();
  if(!a || (a.status!=='published' && !(s && s.role==='admin'))){
    document.title='Attraction not found — StayExplore Kenya';
    root.innerHTML=`<div class="eyebrow">Attraction</div><h1>Attraction not found.</h1><p class="sub">This attraction isn't available right now.</p><div class="actions" style="margin-top:20px"><a class="btn" href="search.html#attractions">Browse attractions</a></div>`; return;
  }
  document.title=`${a.name} — StayExplore Kenya`;
  const imgs=(a.images&&a.images.length?a.images:defaultImages(a)), stays=nearbyStays(a);
  const findHref=hasGeo(a)?nearHref(a,a.name,'stays'):(a.region?`search.html?region=${encodeURIComponent(a.region)}`:`search.html?q=${encodeURIComponent(a.county||a.name)}`);
  const fact=(t,v)=>v?`<div class="kpi"><div class="eyebrow">${t}</div><strong>${esc(v)}</strong></div>`:'';
  root.innerHTML=`${a.status!=='published'?`<div class="notice" style="margin-bottom:14px"><strong>Draft preview.</strong> Only administrators can see this until it is published.</div>`:''}<div class="eyebrow">${esc(a.category)} · ${esc(a.county)}</div><h1>${esc(a.name)}.</h1><p class="sub">${esc(a.tagline||'')}</p><div class="photo attraction-hero" style="${bgStyle(imgs[0])}"></div>${imgs.length>1?`<div class="thumb-row">${imgs.slice(1,4).map(i=>`<div class="photo" style="${bgStyle(i)}"></div>`).join('')}</div>`:''}<div class="grid grid-3 facts">${fact('Landscape',a.terrain)}${fact('Best time to go',a.bestTime)}${fact('Climate',a.climate)}</div><div class="grid grid-2" style="margin-top:20px"><div class="card pad"><h2>Why visit</h2><p>${esc(a.description||'')}</p><div class="actions"><a class="btn" href="${findHref}">Find nearby stays</a><button class="btn btn-outline" type="button" data-fav="${esc(a.id)}" data-label="♡ Save attraction">♡ Save attraction</button></div></div><div class="card pad"><h2>Nearby</h2><div class="kpi"><strong>Accommodation</strong><span class="small">${stays.length} recommended stay${stays.length===1?'':'s'}</span></div><div class="kpi" style="margin-top:10px"><strong>Best for</strong><span class="small">${esc(a.bestFor||'Sightseeing and day trips')}</span></div></div></div>${hasGeo(a)?`<h2 style="margin-top:34px">On the map</h2><div class="detail-map" id="attrMap" aria-label="Map of ${esc(a.name)} and nearby stays"></div>`:''}<div class="detail-card-heading" style="margin-top:34px"><h2>Stays near ${esc(a.name)}</h2>${stays.length>3?`<a class="home-link" href="${findHref}">View all ${stays.length} stays →</a>`:''}</div>${stays.length?`<div class="grid grid-3">${stays.slice(0,3).map(l=>`<article class="card"><div class="photo" style="${bgStyle(coverOf(l))}"></div><div class="pad"><h3>${esc(l.name)}</h3><p class="small">${esc(l.location)} · KSh ${Math.round(l.price).toLocaleString('en-KE')}/night${l._km!==undefined?' · <strong>'+fmtKm(l._km)+' away</strong>':''}</p><a class="btn btn-sm" href="details.html?id=${encodeURIComponent(l.id)}">View stay</a></div></article>`).join('')}</div>`:`<div class="card pad empty"><p class="small">No approved stays near this attraction yet.</p></div>`}`;
  const am=document.querySelector('#attrMap'); if(am) mountNearMap(am,attrPin(a),stays.slice(0,8).map(stayPin));
}

/* ---------- host: manage listings, edit listing, dashboard numbers ---------- */
function hostListings(){ return getListings().filter(canEditListing); }
function initHostListings(){
  const body=document.querySelector('[data-host-listings]'); if(!body) return;
  const s=getSession();
  const draw=()=>{
    const mine=hostListings();
    body.innerHTML=mine.length?mine.map(l=>`<tr><td><strong>${esc(l.name)}</strong></td><td>${esc(l.location)}</td><td>${money(l.price)}</td><td>${badge(l.status)}</td><td>${l.bookings||0}</td><td class="row-actions"><a class="home-link" href="host-edit-listing.html?id=${encodeURIComponent(l.id)}">Edit</a> · <a class="home-link" href="details.html?id=${encodeURIComponent(l.id)}">View</a>${l.ownerId===s.id?` · <button type="button" class="link-danger" data-del="${esc(l.id)}">Delete</button>`:''}</td></tr>`).join(''):`<tr><td colspan="6" class="small" style="padding:24px">You have no listings yet. Use “Add stay” to create your first one.</td></tr>`;
    const c=st=>mine.filter(l=>l.status===st).length;
    setStat('approved',`${c('approved')} approved`); setStat('pending',`${c('pending')+c('review')} pending`);
  };
  body.addEventListener('click',e=>{
    const b=e.target.closest('[data-del]'); if(!b) return;
    const l=listingById(b.dataset.del); if(!l || !confirm(`Delete “${l.name}”? This cannot be undone.`)) return;
    if(saveListings(getListings().filter(x=>x.id!==l.id))){ draw(); showToast('Listing deleted'); }
  });
  draw();
}
const STATUS_LINE = { approved:'Approved · Visible to tourists', pending:'Pending review · Not yet visible to tourists', review:'Needs review · Not yet visible to tourists', rejected:'Rejected · Edit and save to resubmit', suspended:'Suspended · Not visible to tourists' };
function fillListingForm(form,l){
  const set=(n,v)=>{ const el=form.querySelector(`[name="${n}"]`); if(el) el.value=v??''; };
  ['name','location','price','description','rules'].forEach(n=>set(n,l[n]));
  set('amenities',(l.amenities||[]).join(', '));
  const pm=form.querySelector('[data-pick-map]'); if(pm&&pm.__pick) pm.__pick.set(l.lat,l.lng);
  const sel=form.querySelector('[name="type"]');
  if(sel){ if(l.type && ![...sel.options].some(o=>o.value===l.type||o.text===l.type)) sel.add(new Option(l.type,l.type)); sel.value=l.type||sel.value; }
  const st=form.querySelector('[name="status"]'); if(st) st.value=l.status;
  form.dataset.id=l.id;
}
function paintHostEdit(l){
  document.title=`Edit ${l.name} — StayExplore Kenya`;
  document.querySelectorAll('[data-listing="title"]').forEach(e=>e.textContent=`Edit ${l.name}`);
  document.querySelectorAll('[data-listing="name"]').forEach(e=>e.textContent=l.name);
  document.querySelectorAll('[data-listing="status"]').forEach(e=>e.textContent=STATUS_LINE[l.status]||l.status);
  document.querySelectorAll('[data-listing="link"]').forEach(e=>e.href=`details.html?id=${encodeURIComponent(l.id)}`);
  const c=document.querySelector('#listingCover'); if(c){ c.style.backgroundImage=`url('${coverOf(l)}')`; }
}
function initHostEdit(){
  const form=document.querySelector('#listingForm[data-mode="host-edit"]'); if(!form) return;
  const l=listingById(new URLSearchParams(location.search).get('id')||'');
  if(!l || !canEditListing(l)){ setFlash('That listing could not be found in your account.'); go('host-manage-listings.html'); return; }
  fillListingForm(form,l); paintHostEdit(l);
}

/* shared save for host add / host edit / admin edit */
async function handleListing(form){
  const mode=form.dataset.mode, s=getSession(), v=n=>(form.querySelector(`[name="${n}"]`)?.value||'').trim();
  const f={ name:v('name'), location:v('location'), price:Math.round(+v('price')), type:v('type')||'Apartment', description:v('description'), amenities:splitList(v('amenities')), rules:v('rules') };
  if(f.name.length<3) return formMessage(form,'Please enter a property name (at least 3 characters).');
  if(!f.location) return formMessage(form,'Please enter the location.');
  if(!(f.price>0)) return formMessage(form,'Enter a nightly price greater than zero.');
  if(f.description.length<10) return formMessage(form,'Please add a short description (at least 10 characters).');
  let imgs; try{ imgs=await readImages(form); }catch(e){ return formMessage(form,e.message); }
  const list=getListings(), region=regionOf(`${f.location} ${f.name}`);
  const geo=geoFromForm(form,`${f.location} ${f.name}`,list.find(x=>x.id===form.dataset.id));
  if(geo.error) return formMessage(form,geo.error);
  if(mode==='host-add'){
    const ne=needEmailConfirmed(); if(ne) return formMessage(form,ne);
    const l=Object.assign({id:uniqueId(f.name,list.map(x=>x.id)),region,rating:0,reviews:0,score:78,bookings:0,status:'pending',ownerId:s.id,hostName:s.name,submitted:todayISO(),images:[]},f,geo);
    l.images=imgs.length?imgs:defaultImages(l);
    list.unshift(l);
    if(!saveListings(list)) return formMessage(form,QUOTA_MSG);
    setFlash('Listing submitted. An administrator will review it before it goes live.'); redirecting=true; go('host-manage-listings.html'); return;
  }
  const l=list.find(x=>x.id===form.dataset.id);
  if(!l) return formMessage(form,'This listing no longer exists.');
  if(mode==='host-edit' && !canEditListing(l)) return formMessage(form,'You can only edit your own listings.');
  if(mode==='admin-edit' && s.role!=='admin') return formMessage(form,'Only administrators can do that.');
  const identityChanged=l.status==='approved' && (l.name!==f.name || l.location!==f.location || l.type!==f.type);
  Object.assign(l,f,geo,{region:region||l.region,updated:Date.now()});
  if(imgs.length) l.images=imgs;
  let msg='Listing changes saved';
  if(mode==='host-edit' && identityChanged){ l.status='review'; msg='Saved. The name, location or type changed, so the listing is hidden until an admin re-approves it.'; }
  if(mode==='host-edit' && l.status==='rejected'){ l.status='pending'; msg='Changes saved and listing resubmitted for review'; }
  if(mode==='admin-edit'){ const st=v('status'); if(STATUS[st]) l.status=st; msg='Listing updated'; }
  if(!saveListings(list)) return formMessage(form,QUOTA_MSG);
  [...form.elements].filter(e=>e.type==='file').forEach(i=>i.value='');
  if(mode==='host-edit') paintHostEdit(l); else window.dispatchEvent(new CustomEvent('se-listings-changed'));
  showToast(msg);
}

/* ---------- admin: listing approvals (+ edit) ---------- */
function initAdminListings(){
  const body=document.querySelector('[data-admin-listings]'); if(!body) return;
  const form=document.querySelector('#listingForm[data-mode="admin-edit"]'), panel=document.querySelector('#adminListingPanel');
  const order={review:0,pending:1,approved:2,suspended:3,rejected:4};
  const draw=()=>{
    const all=getListings().slice().sort((a,b)=>(order[a.status]??9)-(order[b.status]??9));
    body.innerHTML=all.map(l=>{
      const id=esc(l.id), pend=l.status==='pending'||l.status==='review';
      const acts=(pend||l.status==='rejected'||l.status==='suspended'?`<button type="button" class="btn btn-sm" data-set="approved" data-id="${id}">Approve</button> `:'')
        +(pend?`<button type="button" class="btn btn-danger btn-sm" data-set="rejected" data-id="${id}">Reject</button> `:'')
        +(l.status==='approved'?`<button type="button" class="btn btn-danger btn-sm" data-set="suspended" data-id="${id}">Suspend</button> `:'')
        +`<button type="button" class="btn btn-outline btn-sm" data-edit="${id}">${l.status==='review'?'Review':'Edit'}</button>`;
      return `<tr><td><strong>${esc(l.name)}</strong></td><td>${hostCell(l)}</td><td>${esc(l.location)}</td><td>${shortDate(l.submitted||todayISO())}</td><td>${badge(l.status)}${integrityWarnings(l).length?` <span class="status warning" title="${esc(integrityWarnings(l).map(c=>c.text).join(' | '))}">${integrityWarnings(l).length} check${integrityWarnings(l).length>1?'s':''}</span>`:''}</td><td class="row-actions">${acts}</td></tr>`;
    }).join('')||`<tr><td colspan="6" class="small" style="padding:24px">No listings yet.</td></tr>`;
    const c=st=>all.filter(l=>l.status===st).length;
    setStat('pending',c('pending')+c('review')); setStat('approved',c('approved')); setStat('rejected',c('rejected')+c('suspended'));
  };
  body.addEventListener('click',e=>{
    const set=e.target.closest('[data-set]'), ed=e.target.closest('[data-edit]');
    if(set){
      const list=getListings(), l=list.find(x=>x.id===set.dataset.id); if(!l) return;
      if(set.dataset.set==='approved'){ const w=integrityWarnings(l); if(w.length && !confirm(`Integrity checks found ${w.length} issue(s):\n\n• ${w.map(c=>c.text).join('\n• ')}\n\nApprove anyway?`)) return; }
      l.status=set.dataset.set; l.updated=Date.now();
      if(saveListings(list)){ draw(); showToast(`${l.name}: ${STATUS[l.status][1].toLowerCase()}`); }
    }
    if(ed && form){
      const l=listingById(ed.dataset.edit); if(!l) return;
      fillListingForm(form,l); form.querySelectorAll('input[type=file]').forEach(i=>i.value='');
      document.querySelector('#adminListingTitle').textContent=`Edit “${l.name}”`;
      const c=document.querySelector('#adminListingCover'); if(c) c.style.backgroundImage=`url('${coverOf(l)}')`;
      document.querySelector('#integrityList').innerHTML=integrityChecks(l).map(c=>`<li class="${c.level}">${c.level==='ok'?'✓':'!'} ${esc(c.text)}</li>`).join('');
      panel.hidden=false; { const pm=form.querySelector('[data-pick-map]'); if(pm&&pm.__pick) pm.__pick.refresh(); }
      panel.scrollIntoView({behavior:'smooth',block:'start'});
    }
  });
  document.querySelector('#adminListingCancel')?.addEventListener('click',()=>{ panel.hidden=true; });
  window.addEventListener('se-listings-changed',draw);
  draw();
}

/* ---------- admin: attractions ---------- */
function initAdminAttractions(){
  const body=document.querySelector('[data-admin-attractions]'); if(!body) return;
  const form=document.querySelector('#attractionForm'), title=document.querySelector('#attractionFormTitle'), cancel=document.querySelector('#attractionCancel'), cover=document.querySelector('#attractionCover');
  const reset=()=>{ form.reset(); const pm=form.querySelector('[data-pick-map]'); if(pm&&pm.__pick) pm.__pick.set('',''); delete form.dataset.id; title.textContent='Add attraction'; cancel.hidden=true; form.querySelector('[type=submit]').textContent='Save attraction'; if(cover){ cover.style.backgroundImage=''; cover.textContent='Choose a photo or leave blank for a landscape-matched default'; } };
  const draw=()=>{
    body.innerHTML=getAttractions().map(a=>{ const id=esc(a.id), pub=a.status==='published';
      return `<tr><td><strong>${esc(a.name)}</strong></td><td>${esc(a.county)}</td><td>${esc(a.category)}</td><td>${nearbyStays(a).length}</td><td>${badge(a.status)}</td><td class="row-actions"><button type="button" class="btn btn-outline btn-sm" data-aedit="${id}">Edit</button> <button type="button" class="btn ${pub?'btn-outline':''} btn-sm" data-atoggle="${id}">${pub?'Unpublish':'Publish'}</button> <button type="button" class="link-danger" data-adel="${id}">Delete</button></td></tr>`; }).join('')||`<tr><td colspan="6" class="small" style="padding:24px">No attractions yet.</td></tr>`;
  };
  body.addEventListener('click',e=>{
    const ed=e.target.closest('[data-aedit]'), tg=e.target.closest('[data-atoggle]'), dl=e.target.closest('[data-adel]');
    if(ed){
      const a=attractionById(ed.dataset.aedit); if(!a) return;
      const set=(n,v)=>{ const el=form.querySelector(`[name="${n}"]`); if(el) el.value=v??''; };
      ['name','county','category','tagline','description','terrain','bestTime'].forEach(n=>set(n,a[n]));
      const pm=form.querySelector('[data-pick-map]'); if(pm&&pm.__pick){ pm.__pick.set(a.lat,a.lng); pm.__pick.refresh(); }
      form.dataset.id=a.id; title.textContent=`Edit ${a.name}`; cancel.hidden=false; form.querySelector('[type=submit]').textContent='Save changes';
      if(cover){ cover.style.backgroundImage=`url('${coverOf(a)}')`; cover.style.backgroundSize='cover'; cover.style.backgroundPosition='center'; cover.textContent=''; }
      form.scrollIntoView({behavior:'smooth',block:'center'});
    }
    if(tg){ const list=getAttractions(), a=list.find(x=>x.id===tg.dataset.atoggle); if(!a) return; a.status=a.status==='published'?'draft':'published'; if(saveAttractions(list)){ draw(); showToast(`${a.name} ${a.status==='published'?'published':'moved to draft'}`); } }
    if(dl){ const a=attractionById(dl.dataset.adel); if(!a||!confirm(`Delete “${a.name}”? This cannot be undone.`)) return; if(saveAttractions(getAttractions().filter(x=>x.id!==a.id))){ if(form.dataset.id===a.id) reset(); draw(); showToast('Attraction deleted'); } }
  });
  cancel.addEventListener('click',reset);
  window.addEventListener('se-attractions-changed',()=>{ draw(); reset(); });
  draw();
}
async function handleAttraction(form){
  const v=n=>(form.querySelector(`[name="${n}"]`)?.value||'').trim();
  const f={ name:v('name'), county:v('county'), category:v('category')||'Wildlife', tagline:v('tagline'), description:v('description'), terrain:v('terrain'), bestTime:v('bestTime') };
  if(f.name.length<2) return formMessage(form,'Please enter the attraction name.');
  if(!f.county) return formMessage(form,'Please enter the county.');
  let imgs; try{ imgs=await readImages(form,3); }catch(e){ return formMessage(form,e.message); }
  const list=getAttractions(), region=regionOf(`${f.county} ${f.name}`)||CATEGORY_REGION[f.category]||null;
  const geo=geoFromForm(form,`${f.county} ${f.name}`,list.find(x=>x.id===form.dataset.id)); if(geo.error) return formMessage(form,geo.error);
  let msg;
  if(form.dataset.id){
    const a=list.find(x=>x.id===form.dataset.id); if(!a) return formMessage(form,'This attraction no longer exists.');
    Object.assign(a,f,geo,{region:regionOf(`${f.county} ${f.name}`)||a.region||region}); if(imgs.length) a.images=imgs; msg='Attraction updated';
  } else {
    const a=Object.assign({id:uniqueId(f.name,list.map(x=>x.id)),region,bestFor:'',climate:'',images:[],status:'draft'},f,geo);
    a.images=imgs.length?imgs:defaultImages(a); list.push(a); msg='Attraction saved as draft';
  }
  if(!saveAttractions(list)) return formMessage(form,QUOTA_MSG);
  showToast(msg); window.dispatchEvent(new CustomEvent('se-attractions-changed'));
}
/* ---------- profiles (tourist, host, admin) ---------- */
function paintAvatar(){
  const s=getSession(); if(!s) return; const a=getAccounts().find(x=>x.id===s.id);
  document.querySelectorAll('[data-avatar]').forEach(el=>{
    const ini=el.querySelector('[data-user="initials"]');
    if(a&&a.avatar){ el.style.backgroundImage=`url('${a.avatar}')`; el.style.backgroundSize='cover'; el.style.backgroundPosition='center'; if(ini) ini.hidden=true; }
    else { el.style.backgroundImage=''; if(ini) ini.hidden=false; }
  });
}
function bindUser(){
  const s=getSession(); if(!s) return;
  document.querySelectorAll('[data-user="name"]').forEach(e=>e.tagName==='INPUT'?e.value=s.name:e.textContent=s.name);
  document.querySelectorAll('[data-user="email"]').forEach(e=>e.tagName==='INPUT'?e.value=s.email:e.textContent=s.email);
  document.querySelectorAll('[data-user="initials"]').forEach(e=>e.textContent=s.name.split(/\s+/).map(w=>w[0]).slice(0,2).join('').toUpperCase());
  document.querySelectorAll('.user-chip').forEach(e=>e.textContent=s.name);
  paintAvatar();
}
function initProfile(){
  const form=document.querySelector('[data-profile-form]'), s=getSession(); if(!form||!s) return;
  const a=getAccounts().find(x=>x.id===s.id)||s;
  document.querySelectorAll('[data-account-badge]').forEach(el=>{ el.textContent=a.role==='host'?`Host · ${VERIF_LABEL[verifState(a)]} · Email ${isEmailConfirmed(a)?'confirmed':'not confirmed'}`:`${roleLabel(a.role)} account · Email ${isEmailConfirmed(a)?'confirmed':'not confirmed'}`; });
  ['phone','country','business'].forEach(n=>{ const el=form.querySelector(`[name="${n}"]`); if(el && a[n]!==undefined) el.value=a[n]; });
}
async function handleProfile(form){
  const s=getSession(), list=getAccounts(), a=list.find(x=>x.id===s.id);
  if(!a) return formMessage(form,'We could not find your account. Please sign out and sign in again.');
  const v=n=>(form.querySelector(`[name="${n}"]`)?.value||'').trim();
  const name=v('name').replace(/\s+/g,' '), email=v('email').toLowerCase();
  if(name.length<2) return formMessage(form,'Please enter your full name.');
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return formMessage(form,'Please enter a valid email address.');
  if(list.some(x=>x.id!==a.id && x.email===email)) return formMessage(form,'Another account already uses that email address.');
  const emailChanged=a.email!==email && a.role!=='admin';
  a.name=name; a.email=email;
  if(emailChanged){ a.emailVerified=false; issueEmailToken(a); }
  ['phone','country','business'].forEach(n=>{ if(form.querySelector(`[name="${n}"]`)) a[n]=v(n); });
  if(!putLS(K.acc,JSON.stringify(list))) return formMessage(form,QUOTA_MSG);
  ls.set(K.ses,JSON.stringify(Object.assign({},getSession(),{name:a.name,email:a.email})));   // keep the same expiry
  bindUser(); showToast(emailChanged?'Profile updated. We sent a confirmation link to your new address (see your demo mailbox).':(form.dataset.success||'Profile updated'));
}
function initPhotoUpload(){
  document.querySelectorAll('[data-photo-input]').forEach(inp=>inp.addEventListener('change',async()=>{
    const f=inp.files&&inp.files[0]; if(!f) return;
    try{
      const data=await readImage(f,320,.85), s=getSession(), list=getAccounts(), a=list.find(x=>x.id===s.id);
      if(!a) throw new Error('Account not found.');
      a.avatar=data; if(!putLS(K.acc,JSON.stringify(list))) throw new Error(QUOTA_MSG);
      paintAvatar(); showToast('Profile photo updated');
    }catch(e){ showToast(e.message||'That photo could not be used.'); }
    inp.value='';
  }));
}

/* ======================================================================
   BOOKINGS — one shared list read by tourist, host and admin screens
   ====================================================================== */
const getAllBookings = ()=>readJSON(ls,K.allbk,[]);
const saveAllBookings = a=>putLS(K.allbk,JSON.stringify(a));
const userBookings = ()=>{ const s=getSession(); return s?getAllBookings().filter(b=>b.guestId===s.id):[]; };
const hostBookings = ()=>{ const ids=new Set(hostListings().map(l=>l.id)); return getAllBookings().filter(b=>ids.has(b.stayId)); };
const addDays = (d,n)=>{ const t=new Date(d+'T00:00:00Z'); t.setUTCDate(t.getUTCDate()+n); return t.toISOString().slice(0,10); };
const bookingState = b=>b.status==='cancelled'?'cancelled':((b.completed||b.checkout<todayISO())?'completed':'confirmed');
const BK_BADGE = { confirmed:'<span class="status success">Confirmed</span>', completed:'<span class="status info">Completed</span>', cancelled:'<span class="status danger">Cancelled</span>' };
const PAY_METHODS = { cash:'Pay on arrival (cash)', bank:'Bank transfer', mpesa:'M-Pesa (sent manually)' };
const payBadge = b=>({pending:'<span class="status warning">Awaiting payment</span>',paid:'<span class="status success">Paid</span>',refunded:'<span class="status info">Refunded</span>',cancelled:'<span class="status info">Not charged</span>'}[b.payment]||'<span class="status info">—</span>');
function payHelp(b){
  const host=getAccounts().find(a=>a.id===b.hostId), hn=host?host.name:'your host', amt=`<strong>${money(b.total)}</strong>`;
  if(b.payment==='paid') return `Payment confirmed by ${esc(hn)}${b.paidAt?' on '+longDate(b.paidAt):''}. Thank you!`;
  if(b.payment==='refunded') return 'This booking was cancelled and the payment is marked as refunded.';
  if(b.payment==='cancelled') return 'This booking was cancelled. You were not charged.';
  if(b.payMethod==='bank') return `Pay ${amt} by bank transfer. ${esc(hn)} will message you their bank details; use <strong>${esc(b.ref)}</strong> as the reference${b.payRef?` (you noted: ${esc(b.payRef)})`:''}. When your host confirms they received it, this changes to Paid.`;
  if(b.payMethod==='mpesa') return `Send ${amt} by M-Pesa to ${esc(hn)}${host&&host.phone?' on <strong>'+esc(host.phone)+'</strong>':' (they will message you their number)'} using <strong>${esc(b.ref)}</strong> as the reference${b.payRef?`. You entered the M-Pesa code <strong>${esc(b.payRef)}</strong>`:''}. When your host confirms they received it, this changes to Paid.`;
  return `Pay ${amt} in cash to ${esc(hn)} when you arrive. Your host marks it as paid and this updates.`;
}
const fmtRange = b=>`${fmtDate(b.checkin)} – ${fmtDate(b.checkout)}`;
const fmtStamp = t=>new Date(t).toLocaleDateString('en-GB',{day:'2-digit',month:'short'});
const joinedOf = a=>a.created||+((String(a.id).match(/\d{10,}/)||[0])[0]);

/* returns a message when the dates can't be booked, '' when they are free */
function stayConflict(stayId,ci,co){
  const l=listingById(stayId);
  if(getAllBookings().some(b=>b.stayId===stayId && b.status!=='cancelled' && ci<b.checkout && co>b.checkin)) return 'Those dates are already booked. Please choose different dates.';
  if(l && (l.blocked||[]).some(r=>ci<addDays(r.to,1) && co>r.from)) return 'The host is not accepting bookings for some of those dates. Please choose different dates.';
  return '';
}
function addBooking(b){
  const s=getSession(), all=getAllBookings(); all.unshift(b);
  if(!saveAllBookings(all)) return false;
  const L=getListings(), l=L.find(x=>x.id===b.stayId); if(l){ l.bookings=(l.bookings||0)+1; saveListings(L); }
  ls.set(K.last+s.id,JSON.stringify(b)); return true;
}
function cancelBooking(ref,by){
  const all=getAllBookings(), b=all.find(x=>x.ref===ref); if(!b || b.status==='cancelled') return false;
  b.status='cancelled'; b.cancelledBy=by; b.payment=(b.payment==='paid'?'refunded':'cancelled'); b.cancelledAt=Date.now();
  if(!saveAllBookings(all)) return false;
  const L=getListings(), l=L.find(x=>x.id===b.stayId); if(l){ l.bookings=Math.max(0,(l.bookings||0)-1); saveListings(L); }
  return true;
}
/* called when the guest pays. Returns an error message, or '' when the booking was saved */
function completeBooking(){
  const s=getSession(); if(!s) return 'Please sign in to book.';
  const ne=needEmailConfirmed(); if(ne) return ne;
  const d=getDraft()||Object.assign({stayId:'karura-house',checkin:'2026-10-14',checkout:'2026-10-18',guests:'2 guests'},STAYS['karura-house']);
  const l=listingById(d.stayId||'karura-house');
  if(!l || l.status!=='approved') return 'This stay is not open for booking right now.';
  if(!d.checkin || !d.checkout || nightsBetween(d.checkin,d.checkout)<1) return 'Please choose valid dates.';
  const mEl=document.querySelector('input[name=method]:checked'); if(!mEl) return 'Please choose how you will pay the host.';
  const c=stayConflict(l.id,d.checkin,d.checkout); if(c) return c;
  const p=priceOf(Object.assign({},d,{price:l.price})), taken=new Set(getAllBookings().map(b=>b.ref)); let ref;
  do{ ref='SE-'+new Date().getFullYear()+'-'+String(Math.floor(1000+Math.random()*9000)); }while(taken.has(ref));
  const b={ref,stayId:l.id,stay:l.name,place:l.location,hostId:l.ownerId,checkin:d.checkin,checkout:d.checkout,guests:d.guests||'2 guests',nights:p.n,subtotal:p.sub,fee:p.fee,total:p.total,status:'confirmed',payment:'pending',payMethod:mEl.value,payRef:((document.querySelector('[name=reference]')||{}).value||'').trim().slice(0,40),guestId:s.id,guestName:s.name,guestEmail:s.email,phone:d.phone||'',created:Date.now()};
  if(!addBooking(b)) return QUOTA_MSG;
  ss.del(K.draft); return '';
}

/* ---------- tourist: My bookings ---------- */
function initTouristBookings(){
  const body=document.querySelector('[data-user-bookings]'); if(!body) return;
  const draw=()=>{
    const list=userBookings().sort((a,b)=>b.created-a.created), today=todayISO();
    body.innerHTML=list.length?list.map(b=>{ const st=bookingState(b);
      return `<tr><td><strong>${esc(b.stay)}</strong><div class="small">${esc(b.place)} · ${esc(b.ref)}</div></td><td>${fmtRange(b)}</td><td>${esc(parseInt(b.guests)||1)}</td><td>${money(b.total)}</td><td>${BK_BADGE[st]}<div class="small" style="margin-top:4px">${payBadge(b)}</div>${st==='completed'?`<div class="small" style="margin-top:4px">${esc(revealLine(b.ref,'guest'))}</div>`:''}</td><td class="row-actions"><a class="home-link" href="confirmation.html?ref=${encodeURIComponent(b.ref)}">View</a>${st==='completed'?` · <a class="home-link" href="review.html?ref=${encodeURIComponent(b.ref)}">${reviewsFor(b.ref).some(r=>r.dir==='guest')?'Your review':'Review'}</a>`:''}${st==='confirmed'?` · <button type="button" class="link-danger" style="color:var(--forest)" data-complete="${esc(b.ref)}" title="Demo shortcut: pretend the stay has finished so you can try reviews">Mark completed (demo)</button>`:''}${st==='confirmed'&&b.checkin>=today?` · <button type="button" class="link-danger" data-cancel="${esc(b.ref)}">Cancel</button>`:''} · <a class="home-link" href="messages.html?stay=${encodeURIComponent(b.stayId)}">Message host</a> · <a class="home-link" href="report.html?ref=${encodeURIComponent(b.ref)}">Report</a></td></tr>`; }).join('')
      :`<tr><td colspan="6" class="small" style="padding:24px">You have no bookings yet. <a class="home-link" href="search.html">Find a stay</a></td></tr>`;
    const n=k=>list.filter(b=>bookingState(b)===k).length, set=(k,v)=>document.querySelectorAll(`[data-bind="${k}"]`).forEach(e=>e.textContent=v);
    set('upcoming',`${n('confirmed')} trip${n('confirmed')===1?'':'s'}`); set('history',`${n('completed')} stay${n('completed')===1?'':'s'}`);
  };
  body.addEventListener('click',e=>{ const c=e.target.closest('[data-complete]'); if(c){ const all=getAllBookings(), b=all.find(x=>x.ref===c.dataset.complete); if(b){ b.completed=true; b.completedAt=Date.now(); if(saveAllBookings(all)){ draw(); showToast('Stay marked completed (demo). You can now review it.'); } } return; }
    const b=e.target.closest('[data-cancel]'); if(!b || !confirm('Cancel this booking? Your payment will be refunded.')) return; if(cancelBooking(b.dataset.cancel,'guest')){ draw(); showToast('Booking cancelled'); } });
  draw();
}

/* ---------- host: bookings, payments, availability, dashboard ---------- */
function initHostBookings(){
  const body=document.querySelector('[data-host-bookings]'); if(!body) return;
  const draw=()=>{
    const list=hostBookings().sort((a,b)=>b.created-a.created);
    body.innerHTML=list.length?list.map(b=>{ const st=bookingState(b);
      return `<tr><td><strong>${esc(b.guestName)}</strong><div class="small">${esc(b.guestEmail||'')}${b.phone?' · '+esc(b.phone):''}</div></td><td>${esc(b.stay)}<div class="small">${esc(b.ref)}</div></td><td>${fmtRange(b)}</td><td>${money(b.total)}<div class="small">${b.nights||nightsBetween(b.checkin,b.checkout)} nights · ${esc(b.guests)}</div></td><td>${BK_BADGE[st]}<div class="small" style="margin-top:4px">${payBadge(b)}</div>${st==='completed'?`<div class="small" style="margin-top:4px">${esc(revealLine(b.ref,'host'))}</div>`:''}</td><td class="row-actions">${st==='confirmed'?`<button type="button" class="btn btn-outline btn-sm" data-cancel="${esc(b.ref)}">Cancel</button> `:''}${st==='completed'?'<a class="home-link" href="host-reviews.html">Review guest</a> · ':''}<a class="home-link" href="host-messages.html?stay=${encodeURIComponent(b.stayId)}&guest=${encodeURIComponent(b.guestId)}">Message</a> · <a class="home-link" href="host-report-incident.html?ref=${encodeURIComponent(b.ref)}&type=guest">Flag guest</a> · <a class="home-link" href="host-report-incident.html?ref=${encodeURIComponent(b.ref)}">Report</a></td></tr>`; }).join('')
      :`<tr><td colspan="6" class="small" style="padding:24px">No bookings yet. When a guest books one of your stays it appears here straight away.</td></tr>`;
    const n=k=>list.filter(b=>bookingState(b)===k).length;
    setStat('bk-upcoming',n('confirmed')); setStat('bk-done',n('completed')); setStat('bk-cancelled',n('cancelled'));
  };
  body.addEventListener('click',e=>{ const b=e.target.closest('[data-cancel]'); if(!b || !confirm('Cancel this booking? The guest will be refunded and the dates reopen.')) return; if(cancelBooking(b.dataset.cancel,'host')){ draw(); showToast('Booking cancelled'); } });
  draw();
}
function initHostPayments(){
  const body=document.querySelector('[data-host-payments]'); if(!body) return;
  const draw=()=>{
    const list=hostBookings().sort((a,b)=>b.created-a.created), live=list.filter(b=>b.status!=='cancelled');
    body.innerHTML=list.length?list.map(b=>`<tr><td>${esc(b.ref)}</td><td>${esc(b.guestName)}</td><td>${fmtStamp(b.created)}</td><td>${esc(b.stay)}</td><td>${esc(PAY_METHODS[b.payMethod]||'—')}${b.payRef?`<div class="small">Ref: ${esc(b.payRef)}</div>`:''}</td><td>${money(b.total)}</td><td>${payBadge(b)}</td><td>${b.status==='cancelled'?'':b.payment==='paid'?`<button type="button" class="link-danger" data-pay="pending" data-ref="${esc(b.ref)}">Mark unpaid</button>`:`<button type="button" class="btn btn-sm" data-pay="paid" data-ref="${esc(b.ref)}">Mark as paid</button>`}</td></tr>`).join('')
      :`<tr><td colspan="8" class="small" style="padding:24px">No payments yet. Bookings appear here as soon as a guest books.</td></tr>`;
    const paid=live.filter(b=>b.payment==='paid'), pend=live.filter(b=>b.payment==='pending'), gross=paid.reduce((t,b)=>t+b.total,0), fees=paid.reduce((t,b)=>t+(b.fee||0),0);
    setStat('pay-gross',money(gross)); setStat('pay-fees',money(fees)); setStat('pay-earn',money(gross-fees)); setStat('pay-pending',money(pend.reduce((t,b)=>t+b.total,0)));
  };
  body.addEventListener('click',e=>{
    const b=e.target.closest('[data-pay]'); if(!b) return; const all=getAllBookings(), bk=all.find(x=>x.ref===b.dataset.ref); if(!bk||bk.status==='cancelled') return;
    if(b.dataset.pay==='pending' && !confirm('Mark this payment as not received?')) return;
    bk.payment=b.dataset.pay; bk.paidAt=bk.payment==='paid'?Date.now():null;
    if(saveAllBookings(all)){ draw(); showToast(bk.payment==='paid'?'Payment marked as received. The guest can now see it as Paid.':'Payment marked as awaiting'); }
  });
  draw();
}
function initHostDashboard(){
  if(!document.querySelector('[data-stat="host-active"]')) return;
  const mine=hostListings(), a=mine.filter(l=>l.status==='approved').length, p=mine.filter(l=>l.status==='pending'||l.status==='review').length;
  setStat('host-active',a); setStat('host-active-sub',`${a} approved · ${p} pending`); setStat('host-pending',`${p} listing${p===1?'':'s'}`);
  const B=hostBookings(), live=B.filter(b=>bookingState(b)==='confirmed'), paid=B.filter(b=>b.payment==='paid'&&b.status!=='cancelled'), pendAmt=B.filter(b=>b.payment==='pending'&&b.status!=='cancelled').reduce((t,b)=>t+b.total,0);
  setStat('host-upcoming',live.length); setStat('host-upcoming-sub',`${B.length} booking${B.length===1?'':'s'} in total`);
  setStat('host-income',money(paid.reduce((t,b)=>t+b.total-(b.fee||0),0))); setStat('host-income-sub',`From ${paid.length} paid booking${paid.length===1?'':'s'}${pendAmt?' · '+money(pendAmt)+' awaiting':''}`);
  const mineIds=new Set(mine.map(l=>l.id)), reviews=getReviews().filter(r=>mineIds.has(r.stayId)&&r.dir==='guest');
  const publishedReviews=reviews.filter(r=>r.modStatus==='ok'&&isRevealed(r.ref));
  const avg=publishedReviews.length?publishedReviews.reduce((t,r)=>t+r.rating,0)/publishedReviews.length:0;
  setStat('host-rating',avg?avg.toFixed(1):'New'); setStat('host-rating-sub',`${publishedReviews.length} published guest review${publishedReviews.length===1?'':'s'}`);
  const session=getSession(), reports=getIncidents().filter(i=>i.reporterId===session.id&&i.reporterRole==='host');
  setStat('host-reviews',`${reviews.length} guest review${reviews.length===1?'':'s'}`);
  setStat('host-reports',`${reports.length} report${reports.length===1?'':'s'}`);
  const series=monthlySeries(paid,b=>b.paidAt||b.created,b=>Math.max(0,(+b.total||0)-(+b.fee||0)));
  drawMonthlyTrend(document.querySelector('[data-host-income-chart]'),series,'Host net income from paid bookings over the last six months',money);
  const note=document.querySelector('[data-host-income-note]');
  if(note) note.textContent=paid.length?'Monthly net income from paid bookings; unpaid and cancelled bookings are excluded.':'No paid bookings yet; the trend is zero for the last six months.';
  const body=document.querySelector('[data-host-recent]');
  if(body){ const rec=B.slice().sort((x,y)=>y.created-x.created).slice(0,5);
    body.innerHTML=rec.length?rec.map(b=>`<tr><td>${esc(b.guestName)}</td><td>${esc(b.stay)}</td><td>${fmtRange(b)}</td><td>${BK_BADGE[bookingState(b)]}</td></tr>`).join(''):`<tr><td colspan="4" class="small" style="padding:18px">No bookings yet.</td></tr>`; }
}
function initHostAvailability(){
  const cal=document.querySelector('#calendar'); if(!cal) return;
  const sel=document.querySelector('#availListing'), mine=hostListings();
  if(!mine.length){ cal.innerHTML='<p class="small">Add a listing to manage its availability.</p>'; return; }
  sel.innerHTML=mine.map(l=>`<option value="${esc(l.id)}">${esc(l.name)}</option>`).join('');
  let y=new Date().getUTCFullYear(), m=new Date().getUTCMonth();
  const pad=n=>String(n).padStart(2,'0');
  const draw=()=>{
    const id=sel.value, l=listingById(id), B=getAllBookings().filter(b=>b.stayId===id && b.status!=='cancelled'), blocks=l.blocked||[], today=todayISO();
    const first=new Date(Date.UTC(y,m,1)), days=new Date(Date.UTC(y,m+1,0)).getUTCDate(), off=(first.getUTCDay()+6)%7;
    document.querySelector('#calTitle').textContent=first.toLocaleDateString('en-GB',{month:'long',year:'numeric',timeZone:'UTC'});
    let h=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(d=>`<div class="day"><strong>${d}</strong></div>`).join('')+'<div class="day empty"></div>'.repeat(off);
    for(let d=1;d<=days;d++){
      const ds=`${y}-${pad(m+1)}-${pad(d)}`, bk=B.find(b=>ds>=b.checkin && ds<b.checkout), bi=blocks.findIndex(r=>ds>=r.from && ds<=r.to);
      if(bk) h+=`<div class="day booked" data-ref="${esc(bk.ref)}" title="${esc(bk.guestName)}"><strong>${d}</strong><br><span class="small">${esc(bk.guestName.split(' ')[0])}</span></div>`;
      else if(bi>-1) h+=`<div class="day blocked" data-block="${bi}"><strong>${d}</strong><br><span class="small">Blocked</span></div>`;
      else if(ds<today) h+=`<div class="day"><strong>${d}</strong></div>`;
      else h+=`<div class="day available"><strong>${d}</strong><br><span class="small">Open</span></div>`;
    }
    cal.innerHTML=h;
    const up=B.filter(b=>b.checkout>=today).sort((a,b)=>a.checkin.localeCompare(b.checkin));
    document.querySelector('#availList').innerHTML=up.length?up.map(b=>`<div class="kpi" style="margin-top:8px"><strong>${esc(b.guestName)}</strong><span class="small">${fmtRange(b)} · ${esc(b.ref)}</span></div>`).join(''):'<p class="small">No upcoming bookings for this stay.</p>';
  };
  cal.addEventListener('click',e=>{
    const bk=e.target.closest('[data-ref]'), bl=e.target.closest('[data-block]');
    if(bk){ const b=getAllBookings().find(x=>x.ref===bk.dataset.ref); if(b) showToast(`${b.guestName} · ${fmtRange(b)} · ${b.ref}`); }
    if(bl){ const L=getListings(), l=L.find(x=>x.id===sel.value); if(!l||!canEditListing(l)||!confirm('Reopen these blocked dates?')) return; const r=(l.blocked||[])[+bl.dataset.block]; l.blocked=(l.blocked||[]).filter(x=>x!==r&&!(x.from===r.from&&x.to===r.to)); if(saveListings(L)){ draw(); showToast('Dates reopened'); } }
  });
  sel.addEventListener('change',draw);
  document.querySelector('#calPrev').addEventListener('click',()=>{ m--; if(m<0){ m=11; y--; } draw(); });
  document.querySelector('#calNext').addEventListener('click',()=>{ m++; if(m>11){ m=0; y++; } draw(); });
  document.querySelector('#blockBtn').addEventListener('click',()=>{
    const from=document.querySelector('#blockFrom').value, to=document.querySelector('#blockTo').value;
    if(!from||!to||to<from) return showToast('Choose a start date and an end date on or after it.');
    if(to<todayISO()) return showToast('You can only block dates from today onwards.');
    const L=getListings(), l=L.find(x=>x.id===sel.value); if(!l||!canEditListing(l)) return;
    if(getAllBookings().some(b=>b.stayId===l.id && b.status!=='cancelled' && from<b.checkout && addDays(to,1)>b.checkin)) return showToast('Those dates include a confirmed booking. Cancel it first.');
    (l.blocked=l.blocked||[]).push({from,to});
    if(!saveListings(L)) return showToast(QUOTA_MSG);
    document.querySelector('#blockFrom').value=document.querySelector('#blockTo').value=''; y=+from.slice(0,4); m=+from.slice(5,7)-1; draw(); showToast('Availability updated');
  });
  draw();
}

/* ---------- admin: users (real accounts), dashboard ---------- */
function hostCell(l){
  const acc=getAccounts().find(a=>a.id===l.ownerId);
  return acc?`${esc(acc.name)}<div class="small">${esc(acc.email)}${acc.sample?' · sample account':''}</div>`:`${esc(l.hostName||'—')}<div class="small">No account</div>`;
}
function initAdminUsers(){
  const body=document.querySelector('[data-admin-users]'); if(!body) return;
  const me=getSession(), filter=document.querySelector('#userFilter'), openVerify=initVerifyReview();
  const draw=()=>{
    const accs=getAccounts(), L=getListings(), B=getAllBookings(), f=filter?filter.value:'all';
    const rows=accs.filter(a=>f==='all'||(f==='verify'?(a.role==='host'&&verifState(a)==='pending'):a.role===f)).sort((a,b)=>joinedOf(b)-joinedOf(a));
    body.innerHTML=rows.map(a=>{
      const owned=new Set(L.filter(l=>l.ownerId===a.id).map(l=>l.id));
      const flags=a.role==='tourist'?getIncidents().filter(i=>i.againstId===a.id&&i.reporterRole==='host').length:0;
      const act=a.role==='host'?`${owned.size} listing${owned.size===1?'':'s'} · ${B.filter(b=>owned.has(b.stayId)).length} bookings`:a.role==='tourist'?`${B.filter(b=>b.guestId===a.id).length} booking${B.filter(b=>b.guestId===a.id).length===1?'':'s'}${flags?` · <span style="color:var(--danger);font-weight:700">${flags} host flag${flags===1?'':'s'}</span>`:''}`:'—';
      const j=joinedOf(a);
      return `<tr><td><strong>${esc(a.name)}</strong>${a.sample?' <span class="small">· sample</span>':''}</td><td>${esc(a.email)}</td><td>${esc(roleLabel(a.role))}</td><td>${j?fmtStamp(j):'—'}</td><td class="small">${act}</td><td>${a.suspended?'<span class="status danger">Suspended</span>':'<span class="status success">Active</span>'}${isEmailConfirmed(a)?'':' <span class="status warning">Email unconfirmed</span>'}${a.role==='host'?' '+VERIF_BADGE(verifState(a)):''}</td><td>${a.role==='host'&&['pending','rejected','verified'].includes(verifState(a))&&a.verification?`<button type="button" class="btn ${verifState(a)==='pending'?'':'btn-outline '}btn-sm" data-vr-open="${esc(a.id)}">${verifState(a)==='pending'?'Review ID':'View ID'}</button> `:''}${a.role==='admin'||a.id===me.id?'':`<button type="button" class="btn ${a.suspended?'':'btn-danger '}btn-sm" data-susp="${esc(a.id)}">${a.suspended?'Reactivate':'Suspend'}</button>`}</td></tr>`; }).join('')||`<tr><td colspan="7" class="small" style="padding:24px">No users match.</td></tr>`;
    setStat('u-total',accs.length); setStat('u-hosts',accs.filter(a=>a.role==='host').length); setStat('u-tourists',accs.filter(a=>a.role==='tourist').length); setStat('u-pending',accs.filter(a=>a.role==='host'&&verifState(a)==='pending').length);
  };
  window.addEventListener('se-users-changed',draw);
  body.addEventListener('click',e=>{
    const vo=e.target.closest('[data-vr-open]'); if(vo){ openVerify(vo.dataset.vrOpen); return; }
    const b=e.target.closest('[data-susp]'); if(!b) return;
    const accs=getAccounts(), a=accs.find(x=>x.id===b.dataset.susp); if(!a||a.role==='admin') return;
    if(!a.suspended && !confirm(`Suspend ${a.name}? They will be signed out and unable to sign in.`)) return;
    a.suspended=!a.suspended; if(putLS(K.acc,JSON.stringify(accs))){ draw(); showToast(a.suspended?'Account suspended':'Account reactivated'); }
  });
  filter&&filter.addEventListener('change',draw); draw();
}
function initAdminDashboard(){
  if(!document.querySelector('[data-stat="admin-total"]')) return;
  const all=getListings(), p=all.filter(l=>l.status==='pending'||l.status==='review').length, accs=getAccounts(), B=getAllBookings();
  setStat('admin-total',all.length); setStat('admin-pending-sub',`${p} pending approval`); setStat('admin-pending',`${p} pending`);
  setStat('admin-users',accs.length); setStat('admin-users-sub',`${accs.filter(a=>a.role==='host').length} hosts · ${accs.filter(a=>a.role==='tourist').length} tourists`);
  setStat('admin-inc',getIncidents().length); setStat('admin-inc-sub',`${getIncidents().filter(i=>i.status==='open').length} open · ${getIncidents().filter(i=>i.severity==='critical'&&(i.status==='open'||i.status==='investigating')).length} critical`); setStat('admin-flagged',`${getReviews().filter(r=>r.flagged&&r.modStatus==='ok').length} flagged`); setStat('admin-inc-open',`${getIncidents().filter(i=>i.status==='open'||i.status==='investigating').length} open`);
  setStat('admin-bookings',B.length); setStat('admin-bookings-sub',`${B.filter(b=>bookingState(b)==='confirmed').length} upcoming`);
  const userSeries=monthlySeries(accs,a=>joinedOf(a),()=>1);
  drawMonthlyTrend(document.querySelector('[data-admin-users-chart]'),userSeries,'New user accounts created each month over the last six months',n=>`${n} user${n===1?'':'s'}`);
  const body=document.querySelector('[data-admin-recent]');
  if(body){
    const items=[...all.map(l=>({t:Date.parse(l.submitted)||0,item:l.name,type:'Listing',owner:(getAccounts().find(a=>a.id===l.ownerId)||{name:l.hostName||'—'}).name,badge:badge(l.status)})),
                 ...B.map(b=>({t:b.created,item:b.ref+' · '+b.stay,type:'Booking',owner:b.guestName,badge:BK_BADGE[bookingState(b)]}))].sort((a,b)=>b.t-a.t).slice(0,6);
    body.innerHTML=items.map(i=>`<tr><td>${esc(i.item)}</td><td>${i.type}</td><td>${esc(i.owner)}</td><td>${i.badge}</td></tr>`).join('');
  }
}

/* ======================================================================
   TRUST & SAFETY
   · verified reviews (completed bookings only) with simultaneous reveal
   · admin review moderation
   · critical incident reporting
   · listing integrity checks
   ====================================================================== */
const REVIEW_WINDOW_DAYS = 14;
const SEED_REVIEWS = [
  {id:'rv-seed-1',seed:true,ref:'seed-1',stayId:'karura-house',dir:'guest',authorName:'Amina Wanjiku',rating:5,text:'Beautiful and peaceful stay. Clean, comfortable and a thoughtful host.',created:Date.parse('2026-08-05'),modStatus:'ok'},
  {id:'rv-seed-2',seed:true,ref:'seed-2',stayId:'high-rise',dir:'guest',authorName:'Daniel Mwangi',rating:4,text:'Great location and easy check-in. The city views at sunset are lovely.',created:Date.parse('2026-08-02'),modStatus:'ok'},
  {id:'rv-seed-3',seed:true,ref:'seed-3',stayId:'high-rise',dir:'guest',authorName:'John Karanja',rating:1,text:'Payment complaint included. The refund took too long and nobody replied.',created:Date.parse('2026-09-21'),modStatus:'ok',flagged:true,flagReason:'Off-topic: payment complaint rather than a stay review'},
  {id:'rv-seed-4',seed:true,ref:'seed-4',stayId:'fernside-retreat',dir:'guest',authorName:'Grace Achieng',rating:5,text:'Lovely garden and an attentive host. We never wanted to leave.',created:Date.parse('2026-07-28'),modStatus:'ok'},
  {id:'rv-seed-5',seed:true,ref:'seed-5',stayId:'mara-savannah-camp',dir:'guest',authorName:'Joy Cherono',rating:5,text:'Waking up to the savannah was unforgettable. The game drives were superb.',created:Date.parse('2026-09-10'),modStatus:'ok'},
  {id:'rv-seed-6',seed:true,ref:'seed-6',stayId:'lamu-old-town-retreat',dir:'guest',authorName:'Peter Kimani',rating:5,text:'Quiet lanes, warm sea and a beautiful house. Highly recommended.',created:Date.parse('2026-09-18'),modStatus:'ok'},
  {id:'rv-seed-7',seed:true,ref:'seed-7',stayId:'mt-kenya-forest-lodge',dir:'guest',authorName:'Sarah Njeri',rating:5,text:'The fireplace after a long hike was perfect. Peaks right outside the window.',created:Date.parse('2026-09-25'),modStatus:'ok'}
];
const SEED_INCIDENTS = [
  {id:'INC-0128',sample:true,reporterId:'sample',reporterName:'Sample tourist',reporterRole:'tourist',stayId:'karura-house',bookingRef:'',againstName:'Karura House',againstId:'host-wanjiku',type:'Physical safety or assault',severity:'critical',description:'A guest reported feeling unsafe after an unknown person entered the compound at night. No injuries.',evidence:[],status:'open',created:Date.parse('2026-10-05'),notes:[],resolution:''},
  {id:'INC-0127',sample:true,reporterId:'sample',reporterName:'Sample host',reporterRole:'host',stayId:'high-rise',bookingRef:'',againstName:'Guest #774',againstId:'',type:'No-show',severity:'standard',description:'Guest did not arrive and did not respond to messages. Nights could not be re-sold.',evidence:[],status:'investigating',created:Date.parse('2026-10-04'),notes:[],resolution:''},
  {id:'INC-0126',sample:true,reporterId:'sample',reporterName:'Sample tourist',reporterRole:'tourist',stayId:'high-rise',bookingRef:'',againstName:'High Rise',againstId:'host-daniel',type:'Misleading listing',severity:'standard',description:'The listing photos showed a balcony that the apartment does not have.',evidence:[],status:'resolved',created:Date.parse('2026-10-03'),notes:[{t:Date.parse('2026-10-04'),by:'Admin',text:'Host updated the photos.'}],resolution:'Thank you. The host has corrected the photos and the listing was re-checked.'}
];
const CRITICAL_TYPES = ['Theft','Abuse or harassment','Physical safety or assault'];
const TOURIST_INCIDENT_TYPES = [...CRITICAL_TYPES,'Property damage','Fraud / payment issue','Misleading listing','House rule issue','Other'];
const HOST_INCIDENT_TYPES = [...CRITICAL_TYPES,'Property damage','Fraudulent booking','No-show','House rule breach','Guest conduct concern','Other'];
const INC_STATUS = { open:['danger','Open'], investigating:['warning','Investigating'], resolved:['success','Resolved'], dismissed:['info','Dismissed'] };
const incBadge = i=>`<span class="status ${INC_STATUS[i.status][0]}">${INC_STATUS[i.status][1]}</span>`;
const sevBadge = i=>i.severity==='critical'?'<span class="status danger">Critical</span>':'<span class="status info">Standard</span>';

const getReviews = ()=>{ let v=readJSON(ls,K.reviews,null); if(!Array.isArray(v)){ v=JSON.parse(JSON.stringify(SEED_REVIEWS)); v.forEach(r=>{ r.hostId=SAMPLE_OWNER[r.stayId]||''; }); putLS(K.reviews,JSON.stringify(v)); } return v; };
const saveReviews = a=>putLS(K.reviews,JSON.stringify(a));
const getIncidents = ()=>{ let v=readJSON(ls,K.incidents,null); if(!Array.isArray(v)){ v=JSON.parse(JSON.stringify(SEED_INCIDENTS)); putLS(K.incidents,JSON.stringify(v)); } return v; };
const saveIncidents = a=>putLS(K.incidents,JSON.stringify(a));
const firstName = n=>String(n||'Guest').trim().split(/\s+/)[0];
const longDate = t=>new Date(t).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
const bookingByRef = ref=>getAllBookings().find(b=>b.ref===ref);
const reviewsFor = ref=>getReviews().filter(r=>r.ref===ref);
const completedAtOf = b=>b.completedAt||Date.parse(b.checkout+'T12:00:00Z');
const windowEnd = b=>completedAtOf(b)+REVIEW_WINDOW_DAYS*864e5;
const windowOpen = b=>Date.now()<=windowEnd(b);

/* SIMULTANEOUS REVEAL: neither side's review is visible until both have submitted, or the window has closed */
function isRevealed(ref){
  const rs=reviewsFor(ref); if(!rs.length) return false;
  if(rs.some(r=>r.seed)) return true;
  if(rs.some(r=>r.dir==='guest') && rs.some(r=>r.dir==='host')) return true;
  const b=bookingByRef(ref); return !!b && Date.now()>windowEnd(b);
}
const publicReviews = stayId=>getReviews().filter(r=>r.stayId===stayId && r.dir==='guest' && r.modStatus==='ok' && isRevealed(r.ref)).sort((a,b)=>b.created-a.created);
function reviewVisibility(r){
  if(r.modStatus==='removed') return ['danger','Removed'];
  if(r.modStatus==='hidden') return ['danger','Hidden by admin'];
  if(!isRevealed(r.ref)) return ['warning','Sealed until reveal'];
  return ['success','Published'];
}
/* listing rating = sample base + revealed, un-moderated guest reviews */
function recomputeRating(stayId){
  const L=getListings(), l=L.find(x=>x.id===stayId); if(!l) return;
  if(l.baseReviews===undefined){ l.baseReviews=l.reviews||0; l.baseRating=l.rating||0; }
  const rs=getReviews().filter(r=>r.stayId===stayId && r.dir==='guest' && !r.seed && r.modStatus==='ok' && isRevealed(r.ref));
  const tot=l.baseReviews+rs.length, sum=rs.reduce((t,r)=>t+r.rating,0), rating=tot?Math.round((l.baseRating*l.baseReviews+sum)/tot*10)/10:0;
  if(l.reviews!==tot || l.rating!==rating){ l.reviews=tot; l.rating=rating; saveListings(L); }
}
function syncRatings(){ new Set(getReviews().filter(r=>!r.seed).map(r=>r.stayId)).forEach(recomputeRating); }
const starPicker = ()=>`<div class="star-picker" data-stars>${[1,2,3,4,5].map(n=>`<button type="button" class="star-btn" data-star="${n}" aria-label="${n} star${n>1?'s':''}">★</button>`).join('')}<input type="hidden" name="rating"></div>`;
function bindStars(root){
  if(root.dataset.starsBound) return; root.dataset.starsBound='1';
  root.addEventListener('click',e=>{ const b=e.target.closest('[data-star]'); if(!b) return; const g=b.closest('[data-stars]'), n=+b.dataset.star; g.querySelector('[name=rating]').value=n; g.querySelectorAll('[data-star]').forEach(x=>x.classList.toggle('active',+x.dataset.star<=n)); });
}
const CAT_SCORE = {Excellent:5,Good:4,Fair:3};
function revealLine(ref,role){
  const rs=reviewsFor(ref), g=rs.some(r=>r.dir==='guest'), h=rs.some(r=>r.dir==='host');
  if(g&&h) return 'Both reviews are revealed';
  if(role==='guest') return g?'Your review is sealed. Waiting for the host to review.':h?'The host has reviewed. Submit yours to reveal both.':'Review not written yet';
  return h?'Your review is sealed. Waiting for the guest to review.':g?'The guest has reviewed. Submit yours to reveal both.':'Review not written yet';
}
const sealedNote = (who,b)=>`Sealed. It will be revealed when ${who} submits theirs, or on ${longDate(windowEnd(b))} when the review window closes.`;

/* Rule-based indicators to help moderators prioritise. They are signals, not proof, and nothing is removed automatically. */
function reviewRisk(r,all){
  let pts=0; const why=[]; const add=(n,t)=>{ pts+=n; why.push(t); };
  const text=String(r.text||''), L=listingById(r.stayId);
  if(r.flagged) add(30,'Reported by the host');
  if(/(https?:\/\/|www\.|[\w.+-]+@[\w-]+\.\w+|\b\d[\d\s-]{8,}\d\b)/i.test(text)) add(25,'Contains a link, email or phone number');
  if(r.dir==='guest' && /refund|payment|paid|scam|fraud/i.test(text) && text.length<200) add(15,'Mentions payment or fraud rather than the stay');
  if(all.some(x=>x.id!==r.id && x.dir===r.dir && x.text.trim().toLowerCase()===text.trim().toLowerCase())) add(35,'Identical text appears in another review');
  if(all.some(x=>x.id!==r.id && x.authorId && x.authorId===r.authorId && Math.abs(x.created-r.created)<10*60000)) add(20,'Author posted several reviews within minutes');
  if(text.length<25) add(10,'Very short review');
  if(text.length>20 && text.replace(/[^A-Za-z]/g,'').length && text.replace(/[^A-Z]/g,'').length/text.replace(/[^A-Za-z]/g,'').length>.6) add(10,'Mostly capital letters');
  if(r.dir==='guest' && (r.rating===1||r.rating===5) && text.length<40) add(10,'Extreme rating with little detail');
  if(r.dir==='guest' && L && +L.rating>0 && Math.abs(r.rating-L.rating)>=3) add(10,'Far from the listing\'s average rating');
  const au=r.authorId&&getAccounts().find(a=>a.id===r.authorId); if(au && joinedOf(au) && r.created-joinedOf(au)<864e5) add(10,'Account created less than a day before the review');
  pts=Math.min(100,pts); return {score:pts,level:pts>=50?'High':pts>=25?'Medium':'Low',why};
}
const riskBadge=k=>`<span class="status ${k.level==='High'?'danger':k.level==='Medium'?'warning':'info'}" title="${esc(k.why.join('; ')||'No risk signals')}">${k.level} · ${k.score}</span>`;

/* ---------- tourist: write a verified review ---------- */
function initReviewPage(){
  const root=document.querySelector('[data-review-page]'); if(!root) return;
  bindStars(root);
  const ref=new URLSearchParams(location.search).get('ref'), mine=userBookings();
  const head='<div class="eyebrow">Verified review</div><h1>Leave a review.</h1>';
  if(!ref){
    const done=mine.filter(b=>bookingState(b)==='completed');
    root.innerHTML=`${head}<p class="sub">Only completed stays can be reviewed.</p>`+(done.length?`<div class="grid grid-2" style="margin-top:22px">${done.map(b=>`<div class="card pad"><h3>${esc(b.stay)}</h3><p class="small">${fmtRange(b)} · ${esc(b.ref)}</p><a class="btn btn-sm" href="review.html?ref=${encodeURIComponent(b.ref)}">${reviewsFor(b.ref).some(r=>r.dir==='guest')?'View your review':'Write review'}</a></div>`).join('')}</div>`:`<div class="card pad empty" style="margin-top:22px"><p class="small">You have no completed stays yet. After your stay you can review it here. <a class="home-link" href="bookings.html">My bookings</a></p></div>`);
    return;
  }
  const b=mine.find(x=>x.ref===ref);
  if(!b){ root.innerHTML=`${head}<div class="card pad empty" style="margin-top:22px"><p class="small">We could not find that booking in your account. <a class="home-link" href="review.html">Back</a></p></div>`; return; }
  const l=listingById(b.stayId), rs=reviewsFor(ref), own=rs.find(r=>r.dir==='guest'), other=rs.find(r=>r.dir==='host'), st=bookingState(b), hostName=(getAccounts().find(a=>a.id===b.hostId)||{name:'the host'}).name;
  const left=`<div><div class="photo" style="min-height:230px;${bgStyle(l?coverOf(l):IMG.nairobiApt)}"></div><h3 style="margin-top:12px">${esc(b.stay)}</h3><div class="small">${esc(b.place)} · ${fmtRange(b)}</div></div>`;
  let right;
  if(st==='cancelled') right='<div class="notice">This booking was cancelled, so it cannot be reviewed.</div>';
  else if(st!=='completed') right='<div class="notice">Only completed bookings can submit a review. You can review this stay once it is finished.</div>';
  else if(own){
    const shown=isRevealed(ref);
    right=`<div class="grid"><div><div class="eyebrow">Your review</div><div class="rating">${stars(own.rating)}</div><p>${esc(own.text)}</p></div>${shown?`<div class="notice"><strong>Revealed.</strong> Your review is now public on the listing.</div>${other?`<div class="card pad"><div class="eyebrow">${esc(firstName(hostName))}'s review of you</div><div class="rating">${stars(other.rating)}</div><p>${esc(other.text)}</p></div>`:''}`:`<div class="notice"><strong>Sealed.</strong> ${sealedNote(esc(firstName(hostName)),b)}</div>`}</div>`;
  }
  else if(!windowOpen(b)) right=`<div class="notice">The review window for this stay closed on ${longDate(windowEnd(b))}.</div>`;
  else right=`<div class="grid" data-review-form data-ref="${esc(ref)}">${other?`<div class="notice"><strong>${esc(firstName(hostName))} has already reviewed this stay.</strong> Their review stays hidden until you submit yours, so neither of you can influence the other.</div>`:''}<div class="field"><label>OVERALL RATING</label>${starPicker()}</div><div class="field"><label>YOUR REVIEW</label><textarea name="text" placeholder="How was your stay?"></textarea></div><div class="grid grid-2">${['Cleanliness','Location','Communication','Value'].map(c=>`<div class="field"><label>${c.toUpperCase()}</label><select name="c-${c.toLowerCase()}"><option>Excellent</option><option>Good</option><option>Fair</option></select></div>`).join('')}</div><div class="notice">Verified stay. Reviews are sealed until the host has also submitted theirs (or ${REVIEW_WINDOW_DAYS} days pass), so neither side can influence the other. Public reviews show your first name only.</div><div class="form-error" role="alert" hidden></div><button type="button" class="btn" data-submit-review>Submit review</button></div>`;
  root.innerHTML=`${head}<p class="sub">Your review is tied to this completed booking.</p><div class="card pad" style="max-width:930px;margin-top:22px"><div class="grid grid-2">${left}${right}</div></div>`;
  const btn=root.querySelector('[data-submit-review]');
  if(btn) btn.addEventListener('click',()=>submitGuestReview(root.querySelector('[data-review-form]')));
}
function submitGuestReview(box){
  const err=m=>{ const e=box.querySelector('.form-error'); e.textContent=m; e.hidden=false; };
  const s=getSession(), b=userBookings().find(x=>x.ref===box.dataset.ref), rating=+box.querySelector('[name=rating]').value, text=box.querySelector('[name=text]').value.trim();
  if(!b || bookingState(b)!=='completed') return err('Only completed bookings can be reviewed.');
  if(!windowOpen(b)) return err('The review window for this stay has closed.');
  if(reviewsFor(b.ref).some(r=>r.dir==='guest')) return err('You have already reviewed this stay.');
  if(!(rating>=1&&rating<=5)) return err('Please choose a star rating.');
  if(text.length<10) return err('Please write at least a sentence (10 characters) about your stay.');
  const cats={}; ['cleanliness','location','communication','value'].forEach(c=>cats[c]=CAT_SCORE[box.querySelector(`[name="c-${c}"]`).value]||5);
  const all=getReviews(); all.push({id:'rv-'+Date.now().toString(36),ref:b.ref,stayId:b.stayId,hostId:b.hostId,guestId:s.id,dir:'guest',authorId:s.id,authorName:s.name,rating,text,cats,created:Date.now(),modStatus:'ok'});
  if(!saveReviews(all)) return err(QUOTA_MSG);
  recomputeRating(b.stayId); showToast('Review submitted'); initReviewPage();
}

/* ---------- host: reviews of my stays + reviewing guests ---------- */
function initHostReviews(){
  const root=document.querySelector('[data-host-reviews]'); if(!root) return;
  bindStars(root);
  const draw=()=>{
    const s=getSession(), mineIds=new Set(hostListings().map(l=>l.id)), rs=getReviews().filter(r=>mineIds.has(r.stayId)&&r.dir==='guest'), B=hostBookings();
    const rows=rs.slice().sort((a,b)=>b.created-a.created).map(r=>{
      const [c,lbl]=reviewVisibility(r), shown=isRevealed(r.ref)&&r.modStatus==='ok', act=shown&&!r.flagged?`<button type="button" class="link-danger" data-flag="${esc(r.id)}">Report</button>`:(r.flagged?'<span class="small">Reported</span>':'');
      return `<tr><td>${esc(firstName(r.authorName))}</td><td>${esc((listingById(r.stayId)||{}).name||'')}</td><td class="rating">${shown?stars(r.rating):'—'}</td><td>${shown?esc(r.text):`<em class="small">${r.modStatus==='ok'?'Hidden until you also review this guest, or the window closes.':'Not public.'}</em>`}</td><td>${shortDate(new Date(r.created).toISOString().slice(0,10))}</td><td><span class="status ${c}">${lbl}</span> ${act}</td></tr>`; }).join('');
    const pend=B.filter(b=>bookingState(b)==='completed' && windowOpen(b) && !reviewsFor(b.ref).some(r=>r.dir==='host'));
    const given=getReviews().filter(r=>r.dir==='host' && mineIds.has(r.stayId));
    const pub=rs.filter(r=>r.modStatus==='ok'&&isRevealed(r.ref)), avg=pub.length?pub.reduce((t,r)=>t+r.rating,0)/pub.length:0;
    root.innerHTML=`<div class="card table-wrap"><table><thead><tr><th>Guest</th><th>Property</th><th>Rating</th><th>Review</th><th>Date</th><th>Status</th></tr></thead><tbody>${rows||'<tr><td colspan="6" class="small" style="padding:24px">No guest reviews yet.</td></tr>'}</tbody></table></div>
    <div class="grid grid-3" style="margin-top:18px"><div class="kpi"><strong>${avg?avg.toFixed(1):'—'}</strong><span class="small">Average of published reviews</span></div><div class="kpi"><strong>${pub.length}</strong><span class="small">Published reviews</span></div><div class="kpi"><strong>${pend.length}</strong><span class="small">Guests awaiting your review</span></div></div>
    <h2 style="margin-top:30px">Review your guests</h2><p class="small">After a completed stay you can review the guest. Both reviews stay sealed until you have each submitted yours (or ${REVIEW_WINDOW_DAYS} days pass).</p>
    <div class="grid grid-2" style="margin-top:12px">${pend.map(b=>`<div class="card pad" data-host-review data-ref="${esc(b.ref)}"><strong>${esc(b.guestName)}</strong><div class="small">${esc(b.stay)} · ${fmtRange(b)} · ${esc(b.ref)}</div><div class="small" style="color:var(--forest);font-weight:700;margin-top:4px">${esc(revealLine(b.ref,'host'))}</div><div class="field" style="margin-top:12px"><label>RATING</label>${starPicker()}</div><div class="field" style="margin-top:10px"><label>YOUR REVIEW OF THE GUEST</label><textarea name="text" placeholder="Communication, respect for house rules, care of the property…"></textarea></div><div class="form-error" role="alert" hidden></div><button type="button" class="btn btn-sm" style="margin-top:10px" data-submit-host-review>Submit review</button></div>`).join('')||'<div class="card pad empty"><p class="small">No completed stays are waiting for your review.</p></div>'}</div>
    ${given.length?`<h3 style="margin-top:26px">Your reviews of guests</h3><div class="card table-wrap"><table><thead><tr><th>Guest</th><th>Stay</th><th>Rating</th><th>Status</th></tr></thead><tbody>${given.map(r=>{ const b=bookingByRef(r.ref); return `<tr><td>${esc(b?b.guestName:'Guest')}</td><td>${esc((listingById(r.stayId)||{}).name||'')}</td><td class="rating">${stars(r.rating)}</td><td>${isRevealed(r.ref)?'<span class="status success">Revealed</span>':'<span class="status warning">Sealed</span>'}</td></tr>`; }).join('')}</tbody></table></div>`:''}`;
  };
  root.addEventListener('click',e=>{
    const f=e.target.closest('[data-flag]');
    if(f){ const reason=(prompt('Why are you reporting this review? (optional)')||'').trim()||'Reported by host'; const all=getReviews(), r=all.find(x=>x.id===f.dataset.flag); if(!r) return; r.flagged=true; r.flagReason=reason; r.flaggedBy=getSession().id; if(saveReviews(all)){ draw(); showToast('Review reported to moderators'); } return; }
    const sb=e.target.closest('[data-submit-host-review]'); if(!sb) return;
    const box=sb.closest('[data-host-review]'), err=m=>{ const x=box.querySelector('.form-error'); x.textContent=m; x.hidden=false; };
    const b=hostBookings().find(x=>x.ref===box.dataset.ref), rating=+box.querySelector('[name=rating]').value, text=box.querySelector('[name=text]').value.trim();
    if(!b||bookingState(b)!=='completed') return err('Only completed stays can be reviewed.');
    if(!windowOpen(b)) return err('The review window has closed.');
    if(reviewsFor(b.ref).some(r=>r.dir==='host')) return err('You have already reviewed this guest.');
    if(!(rating>=1&&rating<=5)) return err('Please choose a star rating.');
    if(text.length<10) return err('Please write at least 10 characters.');
    const all=getReviews(); all.push({id:'rv-'+Date.now().toString(36),ref:b.ref,stayId:b.stayId,hostId:b.hostId,guestId:b.guestId,dir:'host',authorId:getSession().id,authorName:getSession().name,rating,text,created:Date.now(),modStatus:'ok'});
    if(!saveReviews(all)) return err(QUOTA_MSG);
    recomputeRating(b.stayId); draw(); showToast(isRevealed(b.ref)?'Review submitted. Both reviews are now revealed.':'Review submitted. It stays sealed until the guest also reviews.');
  });
  draw();
}

/* ---------- admin: moderate reviews ---------- */
function initAdminReviews(){
  const body=document.querySelector('[data-admin-reviews]'); if(!body) return;
  const filter=document.querySelector('#reviewFilter');
  const draw=()=>{
    const all=getReviews(), f=filter?filter.value:'all';
    const risk=new Map(all.map(r=>[r.id,reviewRisk(r,all)]));
    const rows=all.filter(r=>f==='all'||(f==='risk'&&risk.get(r.id).level==='High'&&r.modStatus==='ok')||(f==='flagged'&&r.flagged&&r.modStatus==='ok')||(f==='sealed'&&!isRevealed(r.ref)&&r.modStatus==='ok')||(f==='hidden'&&r.modStatus!=='ok')).sort((a,b)=>(!!b.flagged-!!a.flagged)||(risk.get(b.id).score-risk.get(a.id).score)||b.created-a.created);
    body.innerHTML=rows.map(r=>{ const [c,lbl]=reviewVisibility(r), id=esc(r.id), k=risk.get(r.id);
      const acts=(r.modStatus==='ok'?`<button type="button" class="btn btn-outline btn-sm" data-mod="hidden" data-id="${id}">Hide</button> `:`<button type="button" class="btn btn-outline btn-sm" data-mod="ok" data-id="${id}">Restore</button> `)+(r.modStatus!=='removed'?`<button type="button" class="btn btn-danger btn-sm" data-mod="removed" data-id="${id}">Remove</button> `:'')+(r.flagged?`<button type="button" class="link-danger" style="color:var(--forest)" data-mod="unflag" data-id="${id}">Dismiss flag</button>`:'');
      return `<tr><td>${esc(r.authorName)}<div class="small">${r.dir==='guest'?'Guest → listing':'Host → guest'}${r.seed?' · sample':' · verified stay'}</div></td><td>${esc((listingById(r.stayId)||{}).name||'')}</td><td>${r.rating}</td><td>${esc(r.text)}${r.flagged?`<div class="small" style="color:var(--danger)">Reported: ${esc(r.flagReason||'')}</div>`:''}</td><td>${riskBadge(k)}<div class="small" style="margin-top:4px">${esc(k.why.slice(0,2).join('; ')||'No risk signals')}</div></td><td><span class="status ${c}">${lbl}</span></td><td class="row-actions">${acts}</td></tr>`; }).join('')||'<tr><td colspan="7" class="small" style="padding:24px">No reviews match.</td></tr>';
    setStat('rv-flagged',all.filter(r=>r.flagged&&r.modStatus==='ok').length); setStat('rv-published',all.filter(r=>r.modStatus==='ok'&&isRevealed(r.ref)).length); setStat('rv-sealed',all.filter(r=>r.modStatus==='ok'&&!isRevealed(r.ref)).length); setStat('rv-removed',all.filter(r=>r.modStatus!=='ok').length);
  };
  body.addEventListener('click',e=>{
    const b=e.target.closest('[data-mod]'); if(!b) return; const all=getReviews(), r=all.find(x=>x.id===b.dataset.id); if(!r) return;
    const m=b.dataset.mod;
    if(m==='removed' && !confirm('Remove this review permanently from the listing?')) return;
    if(m==='unflag'){ r.flagged=false; } else { r.modStatus=m; if(m!=='ok') r.flagged=false; }
    if(saveReviews(all)){ recomputeRating(r.stayId); draw(); showToast({hidden:'Review hidden',ok:'Review restored',removed:'Review removed',unflag:'Flag dismissed'}[m]); }
  });
  filter&&filter.addEventListener('change',draw); draw();
}

/* ---------- incidents (tourist + host report, admin handles) ---------- */
function initIncidentForm(){
  const form=document.querySelector('#incidentForm'); if(!form) return;
  const s=getSession(), role=form.dataset.role, sel=form.querySelector('[name=booking]'), typeSel=form.querySelector('[name=type]'), params=new URLSearchParams(location.search);
  const bks=(role==='host'?hostBookings():userBookings()).sort((a,b)=>b.created-a.created);
  let opts=`<option value="">Not linked to a booking</option>`;
  const stayParam=params.get('stay'), sl=stayParam&&listingById(stayParam); if(sl) opts+=`<option value="stay:${esc(sl.id)}">Listing: ${esc(sl.name)}</option>`;
  opts+=bks.map(b=>`<option value="${esc(b.ref)}">${esc(b.ref)} · ${esc(b.stay)}${role==='host'?' · '+esc(b.guestName):''} · ${fmtRange(b)}</option>`).join('');
  sel.innerHTML=opts; sel.value=params.get('ref')&&bks.some(b=>b.ref===params.get('ref'))?params.get('ref'):(sl?'stay:'+sl.id:'');
  typeSel.innerHTML=(role==='host'?HOST_INCIDENT_TYPES:TOURIST_INCIDENT_TYPES).map(t=>`<option>${t}${CRITICAL_TYPES.includes(t)?' (critical)':''}</option>`).join('');
  if(params.get('type')==='guest'){ const o=[...typeSel.options].find(x=>x.text.startsWith('Guest conduct')); if(o) typeSel.value=o.value; }
  const note=form.querySelector('[data-critical-note]'), upd=()=>{ note.hidden=!/\(critical\)/.test(typeSel.value); }; typeSel.addEventListener('change',upd); upd();
  drawMyIncidents();
}
function drawMyIncidents(){
  const box=document.querySelector('[data-my-incidents]'); if(!box) return; const s=getSession();
  const mine=getIncidents().filter(i=>i.reporterId===s.id).sort((a,b)=>b.created-a.created);
  box.innerHTML=mine.length?mine.map(i=>`<div class="kpi" style="margin-top:8px"><strong>${esc(i.id)} · ${esc(i.type)}</strong><span class="small">${INC_STATUS[i.status][1]} · ${longDate(i.created)}</span>${i.resolution&&(i.status==='resolved'||i.status==='dismissed')?`<span class="small" style="display:block;margin-top:4px"><em>Admin: ${esc(i.resolution)}</em></span>`:''}</div>`).join(''):'<p class="small">You have not submitted any reports.</p>';
}
async function handleIncident(form){
  const s=getSession(), role=form.dataset.role, v=n=>(form.querySelector(`[name="${n}"]`)?.value||'').trim();
  const type=v('type').replace(' (critical)',''), description=v('description'), bk=v('booking');
  if(description.length<20) return formMessage(form,'Please describe what happened (at least 20 characters) so an administrator can act on it.');
  let stayId='', bookingRef='', againstName='', againstId='';
  if(bk.startsWith('stay:')){ const l=listingById(bk.slice(5)); if(l){ stayId=l.id; againstName=l.name; againstId=l.ownerId; } }
  else if(bk){ const b=bookingByRef(bk); if(!b) return formMessage(form,'That booking could not be found.'); stayId=b.stayId; bookingRef=b.ref; if(role==='host'){ againstName=b.guestName; againstId=b.guestId; } else { againstName=b.stay; againstId=b.hostId; } }
  else if(role==='host') return formMessage(form,'Please choose the booking this report is about.');
  const files=[...form.elements].filter(e=>e.type==='file').flatMap(i=>[...(i.files||[])]).slice(0,5), evidence=files.map(f=>f.name);
  let evidenceImages=[]; try{ evidenceImages=await Promise.all(files.filter(f=>/^image\//.test(f.type)).slice(0,3).map(f=>readImage(f,900,.7))); }catch(e){ return formMessage(form,e.message); }
  const all=getIncidents(), n=all.reduce((m,i)=>Math.max(m,+String(i.id).replace(/\D/g,'')||0),0)+1;
  const inc={id:'INC-'+String(n).padStart(4,'0'),reporterId:s.id,reporterName:s.name,reporterRole:role,stayId,bookingRef,againstName:againstName||'—',againstId,type,severity:CRITICAL_TYPES.includes(type)?'critical':'standard',description,evidence,evidenceImages,status:'open',created:Date.now(),notes:[],resolution:''};
  all.unshift(inc); if(!saveIncidents(all)) return formMessage(form,QUOTA_MSG);
  form.reset(); initIncidentForm();
  showToast(inc.severity==='critical'?`Critical report ${inc.id} sent. Administrators have been alerted.`:`Report ${inc.id} submitted`);
}
function initAdminIncidents(){
  const body=document.querySelector('[data-admin-incidents]'); if(!body) return;
  const panel=document.querySelector('#incidentPanel'), form=document.querySelector('#incidentAdminForm'), filter=document.querySelector('#incidentFilter');
  let current=null;
  const rank=i=>(i.status==='open'||i.status==='investigating'?0:1)*2+(i.severity==='critical'?0:1);
  const draw=()=>{
    const all=getIncidents(), f=filter?filter.value:'all';
    const rows=all.filter(i=>f==='all'||i.status===f||(f==='critical'&&i.severity==='critical')).sort((a,b)=>rank(a)-rank(b)||b.created-a.created);
    body.innerHTML=rows.map(i=>`<tr${i.severity==='critical'&&(i.status==='open'||i.status==='investigating')?' style="background:#fdf1ef"':''}><td><strong>${esc(i.id)}</strong> ${sevBadge(i)}</td><td>${esc(i.reporterName)}<div class="small">${esc(i.reporterRole)}</div></td><td>${esc(i.againstName)}</td><td>${esc(i.type)}</td><td>${fmtStamp(i.created)}</td><td>${incBadge(i)}</td><td><button type="button" class="btn btn-outline btn-sm" data-open="${esc(i.id)}">Open</button></td></tr>`).join('')||'<tr><td colspan="7" class="small" style="padding:24px">No incident reports.</td></tr>';
    const c=st=>all.filter(i=>i.status===st).length;
    setStat('inc-critical',all.filter(i=>i.severity==='critical'&&(i.status==='open'||i.status==='investigating')).length); setStat('inc-open',c('open')); setStat('inc-inv',c('investigating')); setStat('inc-res',c('resolved')+c('dismissed'));
  };
  const showPanel=i=>{
    current=i.id; panel.hidden=false;
    document.querySelector('#incTitle').innerHTML=`${esc(i.id)} · ${esc(i.type)} ${sevBadge(i)}`;
    document.querySelector('#incMeta').innerHTML=`Reported ${longDate(i.created)} by <strong>${esc(i.reporterName)}</strong> (${esc(i.reporterRole)}) against <strong>${esc(i.againstName)}</strong>${i.bookingRef?` · booking ${esc(i.bookingRef)}`:''}${i.stayId?` · ${esc((listingById(i.stayId)||{}).name||'')}`:''}`;
    document.querySelector('#incDesc').textContent=i.description;
    document.querySelector('#incEvidence').innerHTML=(i.evidence&&i.evidence.length?'Evidence files: '+esc(i.evidence.join(', ')):'No evidence attached.')+((i.evidenceImages||[]).length?`<div class="evidence-row">${i.evidenceImages.map((d,n)=>`<a href="${d}" target="_blank" rel="noopener" title="Open full size"><img src="${d}" alt="Evidence photo ${n+1}"></a>`).join('')}</div>`:'');
    document.querySelector('#incNotes').innerHTML=(i.notes||[]).map(n=>`<div class="kpi" style="margin-top:6px"><span class="small">${longDate(n.t)} · ${esc(n.by)}</span><div>${esc(n.text)}</div></div>`).join('')||'<p class="small">No internal notes yet.</p>';
    form.querySelector('[name=status]').value=i.status; form.querySelector('[name=resolution]').value=i.resolution||''; form.querySelector('[name=note]').value='';
    const l=i.stayId&&listingById(i.stayId), host=i.againstId&&getAccounts().find(a=>a.id===i.againstId&&a.role==='host'), guest=i.reporterRole==='host'&&i.againstId&&getAccounts().find(a=>a.id===i.againstId&&a.role==='tourist');
    const bt=(act,label,ok)=>ok?`<button type="button" class="btn btn-danger btn-sm" data-act="${act}">${label}</button>`:'';
    document.querySelector('#incActions').innerHTML=bt('susp-listing',`Suspend listing “${l?esc(l.name):''}”`,l&&l.status==='approved')+bt('susp-host',`Suspend host ${host?esc(host.name):''}`,host&&!host.suspended)+bt('susp-guest',`Suspend guest ${guest?esc(guest.name):''}`,guest&&!guest.suspended)||'<span class="small">No account actions available for this report.</span>';
    panel.scrollIntoView({behavior:'smooth',block:'start'});
  };
  const addNote=(i,text)=>{ (i.notes=i.notes||[]).push({t:Date.now(),by:getSession().name,text}); };
  body.addEventListener('click',e=>{ const b=e.target.closest('[data-open]'); if(b){ const i=getIncidents().find(x=>x.id===b.dataset.open); if(i) showPanel(i); } });
  document.querySelector('#incClose').addEventListener('click',()=>{ panel.hidden=true; });
  document.querySelector('#incActions').addEventListener('click',e=>{
    const b=e.target.closest('[data-act]'); if(!b||!current) return; const all=getIncidents(), i=all.find(x=>x.id===current); if(!i) return;
    if(!confirm('Apply this action? It takes effect immediately.')) return;
    if(b.dataset.act==='susp-listing'){ const L=getListings(), l=L.find(x=>x.id===i.stayId); if(l){ l.status='suspended'; saveListings(L); addNote(i,`Listing "${l.name}" suspended pending investigation.`); } }
    else { const accs=getAccounts(), a=accs.find(x=>x.id===i.againstId); if(a){ a.suspended=true; putLS(K.acc,JSON.stringify(accs)); addNote(i,`Account of ${a.name} suspended pending investigation.`); } }
    if(i.status==='open') i.status='investigating'; saveIncidents(all); draw(); showPanel(i); showToast('Action applied');
  });
  form.addEventListener('submit',e=>{ /* handled by bindForms → handleIncidentAdmin */ });
  window.__saveIncidentAdmin=()=>{
    const all=getIncidents(), i=all.find(x=>x.id===current); if(!i) return;
    const v=n=>form.querySelector(`[name="${n}"]`).value.trim(), st=v('status');
    if((st==='resolved'||st==='dismissed') && !v('resolution')) return formMessage(form,'Please add a short message for the reporter explaining the outcome.');
    if(v('note')) addNote(i,v('note'));
    if(st!==i.status) addNote(i,`Status changed to ${INC_STATUS[st][1]}.`);
    i.status=st; i.resolution=v('resolution'); i.updated=Date.now();
    if(saveIncidents(all)){ draw(); showPanel(i); showToast('Incident updated'); }
  };
  filter&&filter.addEventListener('change',draw); draw();
}

/* ---------- listing integrity controls ---------- */
function integrityChecks(l){
  const out=[], L=getListings(), norm=t=>String(t||'').toLowerCase().replace(/[^a-z0-9]/g,'');
  const dup=L.find(x=>x.id!==l.id && x.status!=='rejected' && (norm(x.name)===norm(l.name) || (norm(x.location)===norm(l.location) && x.type===l.type && x.ownerId!==l.ownerId)));
  out.push(dup?{level:'warn',text:`Possible duplicate of “${dup.name}” (${dup.location}).`}:{level:'ok',text:'No duplicate listing found.'});
  out.push(l.price<1500||l.price>60000?{level:'warn',text:`Nightly price KSh ${Math.round(l.price).toLocaleString('en-KE')} is outside the usual range (1,500–60,000).`}:{level:'ok',text:'Price is within the expected range.'});
  const owner=getAccounts().find(a=>a.id===l.ownerId);
  out.push(owner?{level:'ok',text:`Host account exists: ${owner.name}${owner.suspended?' (SUSPENDED)':''}.`}:{level:'warn',text:'The host has no registered account.'});
  if(owner) out.push(verifState(owner)==='verified'?{level:'ok',text:'Host identity is verified.'}:{level:'warn',text:`Host identity is not verified (${VERIF_LABEL[verifState(owner)]||'no ID reviewed'}).`});
  if(owner&&owner.suspended) out.push({level:'warn',text:'The host account is suspended.'});
  out.push(l.coordsSource==='pinned'?{level:'ok',text:'Location pinned on the map.'}:{level:'warn',text:hasGeo(l)?'Map location is only approximate (the host did not pin it).':'No map location; tourists cannot find it on the map.'});
  const own=(l.images||[]).some(i=>String(i).startsWith('data:'));
  if(!own && !(owner&&owner.sample)) out.push({level:'warn',text:'No photos uploaded by the host; showing stock images.'}); else out.push({level:'ok',text:'Property photos present.'});
  out.push((l.description||'').length<60?{level:'warn',text:'Description is very short (under 60 characters).'}:{level:'ok',text:'Description is detailed enough.'});
  out.push((l.amenities||[]).length<3?{level:'warn',text:'Fewer than 3 amenities listed.'}:{level:'ok',text:'Amenities listed.'});
  const crit=getIncidents().filter(i=>i.severity==='critical' && (i.status==='open'||i.status==='investigating') && (i.stayId===l.id || i.againstId===l.ownerId));
  if(crit.length) out.push({level:'warn',text:`${crit.length} open critical incident report${crit.length>1?'s':''} involve this listing or its host.`});
  return out;
}
const integrityWarnings = l=>integrityChecks(l).filter(c=>c.level==='warn');

/* ======================================================================
   GEO — Leaflet + OpenStreetMap + Browser Geolocation (as specified in the project document)
   ====================================================================== */
const KENYA_BOUNDS = [[-4.95,33.85],[5.1,42.0]];
const inKenya = (lat,lng)=>lat>=-4.95 && lat<=5.1 && lng>=33.85 && lng<=42.0;
/* a small built-in gazetteer so place search works even offline; OpenStreetMap search covers everything else */
const KENYA_PLACES = [
  ['Nairobi',-1.2921,36.8219,11],['Westlands',-1.2676,36.8108,13],['Kilimani',-1.2921,36.7872,13],['Karen',-1.3226,36.7085,13],['Gigiri',-1.2330,36.8050,13],
  ['Mombasa',-4.0435,39.6682,11],['Diani',-4.3200,39.5800,12],['Malindi',-3.2192,40.1169,12],['Watamu',-3.3547,40.0247,12],['Kilifi',-3.6305,39.8499,12],['Lamu',-2.2717,40.9020,12],['Shela',-2.2790,40.9160,13],
  ['Kisumu',-0.0917,34.7680,12],['Nakuru',-0.3031,36.0800,11],['Naivasha',-0.7167,36.4333,11],['Eldoret',0.5143,35.2698,12],['Kitale',1.0157,35.0062,12],['Kakamega',0.2827,34.7519,12],['Kisii',-0.6817,34.7667,12],
  ['Nanyuki',0.0167,37.0722,12],['Nyeri',-0.4201,36.9476,12],['Meru',0.0463,37.6559,12],['Embu',-0.5389,37.4596,12],['Thika',-1.0333,37.0693,12],['Machakos',-1.5177,37.2634,12],['Isiolo',0.3546,37.5822,11],
  ['Narok',-1.0783,35.8600,11],['Maasai Mara',-1.4900,35.1400,10],['Talek',-1.4700,35.1450,12],['Amboseli',-2.6527,37.2606,10],['Tsavo',-2.9800,38.4600,9],['Voi',-3.3961,38.5561,11],['Samburu',0.6000,37.5333,10],
  ['Mount Kenya',-0.1521,37.3084,10],['Laikipia',0.3600,36.7800,9],['Garissa',-0.4532,39.6461,11],['Lodwar',3.1190,35.5970,11],['Lake Turkana',3.5,36.0,8],['Lake Victoria',-0.5,33.3,8],['Marsabit',2.3284,37.9899,10],['Hell\'s Gate',-0.9100,36.3200,12]
];
const toRad = d=>d*Math.PI/180;
const distKm = (a,b)=>{ const R=6371, dLat=toRad(b.lat-a.lat), dLng=toRad(b.lng-a.lng), h=Math.sin(dLat/2)**2+Math.cos(toRad(a.lat))*Math.cos(toRad(b.lat))*Math.sin(dLng/2)**2; return 2*R*Math.asin(Math.sqrt(h)); };
const hasGeo = o=>!!o && o.lat!==null && o.lat!=='' && o.lat!==undefined && Number.isFinite(+o.lat) && Number.isFinite(+o.lng);
const fmtKm = k=>k<1?'under 1 km':k<10?k.toFixed(1)+' km':Math.round(k).toLocaleString('en-KE')+' km';
function placeFor(text){
  const t=String(text||'').toLowerCase();
  const hit=KENYA_PLACES.filter(p=>t.includes(p[0].toLowerCase())).sort((a,b)=>b[0].length-a[0].length)[0];
  return hit?{lat:hit[1],lng:hit[2],name:hit[0],zoom:hit[3]}:null;
}
const approvedStays = ()=>getListings().filter(l=>l.status==='approved' && hasGeo(l));
const publishedAttractions = ()=>getAttractions().filter(a=>a.status==='published' && hasGeo(a));
const withDist = (list,c)=>list.map(o=>({o,d:distKm({lat:+c.lat,lng:+c.lng},{lat:+o.lat,lng:+o.lng})})).sort((a,b)=>a.d-b.d);
const nearbyAttractions = (l,r=150)=>hasGeo(l)?withDist(publishedAttractions(),l).filter(x=>x.d<=r).map(x=>Object.assign({},x.o,{_km:x.d})):[];
const nearHref = (c,label,tab)=>`search.html?near=${(+c.lat).toFixed(4)},${(+c.lng).toFixed(4)}&label=${encodeURIComponent(label)}&r=${c.r||100}${tab==='attractions'?'#attractions':''}`;

/* ---------- Leaflet loader + helpers (library is bundled in /vendor, tiles come from OpenStreetMap) ---------- */
let leafletP=null;
function loadLeaflet(){
  if(window.L) return Promise.resolve(window.L);
  if(leafletP) return leafletP;
  leafletP=new Promise((res,rej)=>{
    const css=document.createElement('link'); css.rel='stylesheet'; css.href='vendor/leaflet/leaflet.css'; document.head.appendChild(css);
    const sc=document.createElement('script'); sc.src='vendor/leaflet/leaflet.js'; sc.onload=()=>res(window.L); sc.onerror=()=>{ leafletP=null; rej(new Error('The map library could not be loaded.')); }; document.head.appendChild(sc);
  });
  return leafletP;
}
async function createMap(el,opts={}){
  const L=await loadLeaflet();
  const map=L.map(el,{scrollWheelZoom:false,minZoom:5,maxBounds:[[-9,29],[10,47]],maxBoundsViscosity:.8});
  const tiles=L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:18,attribution:'© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'}).addTo(map);
  map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
  tiles.on('tileerror',()=>el.classList.add('tiles-offline')); tiles.on('tileload',()=>el.classList.remove('tiles-offline'));
  map.fitBounds(opts.bounds||KENYA_BOUNDS);
  map.on('click',()=>map.scrollWheelZoom.enable()); el.addEventListener('mouseleave',()=>map.scrollWheelZoom.disable());
  return map;
}
const pinIcon = kind=>window.L.divIcon({className:'se-pin '+kind,html:'<span></span>',iconSize:[26,26],iconAnchor:[13,26],popupAnchor:[0,-24]});
const popupHTML = i=>`<strong>${esc(i.name)}</strong><br><span class="small">${esc(i.sub||'')}</span>${i.href?`<br><a class="home-link" href="${esc(i.href)}">${esc(i.cta||'View')}</a>`:''}`;
function plot(map,layer,items,fit){
  layer.clearLayers();
  items.forEach(i=>window.L.marker([i.lat,i.lng],{icon:pinIcon(i.kind),title:i.name,keyboard:true,alt:i.name}).bindPopup(popupHTML(i)).addTo(layer));
  if(fit&&items.length){ if(items.length===1) map.setView([items[0].lat,items[0].lng],11); else map.fitBounds(window.L.latLngBounds(items.map(i=>[i.lat,i.lng])),{padding:[40,40],maxZoom:12}); }
}
const stayPin = l=>({kind:'stay',id:l.id,name:l.name,lat:+l.lat,lng:+l.lng,sub:`${l.location} · KSh ${Math.round(l.price).toLocaleString('en-KE')}/night`,href:`details.html?id=${encodeURIComponent(l.id)}`,cta:'View stay'});
const attrPin = a=>({kind:'attraction',id:a.id,name:a.name,lat:+a.lat,lng:+a.lng,sub:`${a.category} · ${a.county}`,href:`attraction-details.html?id=${encodeURIComponent(a.id)}`,cta:'Explore attraction'});

/* a small map showing one item and what is near it (used on stay and attraction pages) */
async function mountNearMap(el,centre,items){
  try{
    const map=await createMap(el), layer=window.L.layerGroup().addTo(map);
    const all=[centre,...items]; plot(map,layer,all,true); setTimeout(()=>map.invalidateSize(),50);
  }catch(e){ el.innerHTML=`<p class="small" style="padding:14px">${esc(e.message)}</p>`; }
}

/* ---------- home page: search anywhere in Kenya ---------- */
function initHomeMap(){
  const root=document.querySelector('#kenyaMap'); if(!root) return;
  const $=s=>root.querySelector(s), input=$('#mapSearch'), box=$('#mapSuggest'), panel=$('#mapResults'), radiusSel=$('#mapRadius');
  let map,layer,extra,centre=null,kind='all',sugg=[],active=-1;
  const counts=()=>({a:publishedAttractions().length,s:approvedStays().length});
  const pinsFor=c=>{ const A=publishedAttractions().map(attrPin), S=approvedStays().map(stayPin); return kind==='stays'?S:kind==='attractions'?A:[...A,...S]; };
  const R=()=>+radiusSel.value||100;

  const listItem=(x,type)=>`<a class="near-item" href="${type==='stay'?`details.html?id=${encodeURIComponent(x.o.id)}`:`attraction-details.html?id=${encodeURIComponent(x.o.id)}`}"><span class="near-dot ${type}"></span><span><strong>${esc(x.o.name)}</strong><span class="small">${type==='stay'?esc(x.o.location)+' · KSh '+Math.round(x.o.price).toLocaleString('en-KE'):esc(x.o.category)+' · '+esc(x.o.county)}</span></span><span class="near-km">${fmtKm(x.d)}</span></a>`;
  const section=(title,type,all,c)=>{
    const inR=all.filter(x=>x.d<=R()), show=inR.length?inR:all.slice(0,3), more=inR.length;
    const label=c.label, wide=!inR.length, href=nearHref({lat:c.lat,lng:c.lng,r:wide?500:R()},label,type==='attraction'?'attractions':'stays');
    return `<div class="near-section"><div class="near-head"><h4>${title}</h4></div>${inR.length?'':`<p class="small">Nothing within ${R()} km. The closest are:</p>`}${show.slice(0,4).map(x=>listItem(x,type)).join('')||'<p class="small">None yet.</p>'}<a class="home-link" href="${href}">${wide?`See ${type==='stay'?'stays':'attractions'} within 500 km of ${esc(label)}`:`View all ${more} ${type==='stay'?(more===1?'stay':'stays'):(more===1?'attraction':'attractions')} near ${esc(label)}`} →</a></div>`;
  };
  const paint=async()=>{
    const n=counts();
    if(!centre){
      panel.innerHTML=`<h3>Across Kenya</h3><p class="small">${n.a} attractions and ${n.s} stays on the map. Search a place above, or use your location, to see what is close to it.</p><div class="near-links"><a class="btn btn-sm" href="search.html#attractions">View all ${n.a} attractions</a><a class="btn btn-outline btn-sm" href="search.html">View all ${n.s} stays</a></div>`;
    } else {
      const A=withDist(publishedAttractions().filter(a=>!(centre.type==='attraction'&&a.id===centre.id)),centre), S=withDist(approvedStays().filter(l=>!(centre.type==='stay'&&l.id===centre.id)),centre);
      /* bidirectional: centred on a stay → attractions first; centred on an attraction → stays first */
      const parts=centre.type==='stay'?[['attraction',section('Attractions near here','attraction',A,centre)],['stay',section('Other stays nearby','stay',S,centre)]]:centre.type==='attraction'?[['stay',section('Stays near here','stay',S,centre)],['attraction',section('Other attractions nearby','attraction',A,centre)]]:[['attraction',section('Attractions nearby','attraction',A,centre)],['stay',section('Stays nearby','stay',S,centre)]];
      const want=kind==='stays'?'stay':kind==='attractions'?'attraction':'';
      panel.innerHTML=`<h3>Near ${esc(centre.label)}</h3>${centre.note?`<p class="small">${esc(centre.note)}</p>`:''}${parts.filter(p=>!want||p[0]===want).map(p=>p[1]).join('')}<button type="button" class="link-danger" style="color:var(--forest)" data-clear>Clear search</button>`;
    }
    if(!map) return;
    plot(map,layer,pinsFor(),false);
    extra.clearLayers();
    if(centre){
      const L=window.L;
      if(centre.type==='me'||centre.type==='place') L.marker([centre.lat,centre.lng],{icon:pinIcon('me'),title:centre.label}).bindPopup(`<strong>${esc(centre.label)}</strong>`).addTo(extra);
      const circle=L.circle([centre.lat,centre.lng],{radius:R()*1000,color:'#0d3b2e',weight:1,fillOpacity:.05,interactive:false}).addTo(extra);
      map.fitBounds(circle.getBounds(),{padding:[20,20],maxZoom:13});
    } else map.fitBounds(KENYA_BOUNDS);
  };
  const choose=c=>{ centre=c; input.value=c.type==='me'?'':c.label; box.hidden=true; active=-1; paint(); root.scrollIntoView({behavior:'smooth',block:'nearest'}); };

  /* ----- suggestions: local first (instant, offline), OpenStreetMap on demand ----- */
  const localMatches=q=>{
    const t=q.toLowerCase(), out=[];
    publishedAttractions().filter(a=>`${a.name} ${a.county} ${a.category}`.toLowerCase().includes(t)).slice(0,4).forEach(a=>out.push({type:'attraction',label:a.name,sub:`Attraction · ${a.county}`,lat:+a.lat,lng:+a.lng,id:a.id}));
    approvedStays().filter(l=>`${l.name} ${l.location}`.toLowerCase().includes(t)).slice(0,4).forEach(l=>out.push({type:'stay',label:l.name,sub:`Stay · ${l.location}`,lat:+l.lat,lng:+l.lng,id:l.id}));
    KENYA_PLACES.filter(p=>p[0].toLowerCase().includes(t)).slice(0,4).forEach(p=>out.push({type:'place',label:p[0],sub:'Place in Kenya',lat:p[1],lng:p[2]}));
    return out;
  };
  const drawSugg=(note)=>{
    box.innerHTML=sugg.map((s,i)=>`<li role="option" data-i="${i}" class="${i===active?'on':''}"><span class="near-dot ${s.type==='stay'?'stay':s.type==='attraction'?'attraction':'place'}"></span><span><strong>${esc(s.label)}</strong><span class="small">${esc(s.sub)}</span></span></li>`).join('')+(note?`<li class="sugg-note">${note}</li>`:'');
    box.hidden=!sugg.length&&!note; input.setAttribute('aria-expanded',String(!box.hidden));
  };
  const update=()=>{
    const q=input.value.trim(); if(q.length<2){ sugg=[]; drawSugg(); return; }
    sugg=localMatches(q); active=sugg.length?0:-1;
    drawSugg(q.length>=3?`<a href="#" data-remote>Search all of Kenya for “${esc(q)}” on OpenStreetMap</a>`:'');
  };
  const remote=async()=>{
    const q=input.value.trim(); if(q.length<3) return;
    drawSugg('Searching OpenStreetMap…');
    try{
      const ctl=new AbortController(), to=setTimeout(()=>ctl.abort(),7000);
      const r=await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=ke&limit=6&q=${encodeURIComponent(q)}`,{signal:ctl.signal,headers:{'Accept':'application/json'}}); clearTimeout(to);
      const data=await r.json();
      sugg=data.map(d=>({type:'place',label:d.display_name.split(',').slice(0,2).join(',').trim(),sub:'OpenStreetMap · '+d.display_name.split(',').slice(-3,-1).join(',').trim(),lat:+d.lat,lng:+d.lon}));
      active=sugg.length?0:-1; drawSugg(sugg.length?'':'No places found in Kenya for that search.');
    }catch(e){ drawSugg('Could not reach OpenStreetMap search. Check your internet connection. Built-in places still work.'); }
  };
  input.addEventListener('input',update); input.addEventListener('focus',update);
  input.addEventListener('keydown',e=>{
    if(e.key==='ArrowDown'){ e.preventDefault(); if(sugg.length){ active=(active+1)%sugg.length; drawSugg(); } }
    else if(e.key==='ArrowUp'){ e.preventDefault(); if(sugg.length){ active=(active-1+sugg.length)%sugg.length; drawSugg(); } }
    else if(e.key==='Enter'){ e.preventDefault(); if(sugg[active]) choose(sugg[active]); else remote(); }
    else if(e.key==='Escape'){ box.hidden=true; }
  });
  box.addEventListener('mousedown',e=>{ e.preventDefault(); const li=e.target.closest('[data-i]'), rm=e.target.closest('[data-remote]'); if(li) choose(sugg[+li.dataset.i]); else if(rm) remote(); });
  document.addEventListener('click',e=>{ if(!root.contains(e.target)) box.hidden=true; });
  $('#mapGo').addEventListener('click',()=>{ if(sugg[active]) choose(sugg[active]); else if(input.value.trim().length>=3) remote(); else input.focus(); });

  /* ----- Browser Geolocation API ----- */
  $('#mapNear').addEventListener('click',()=>{
    if(!navigator.geolocation) return showToast('Your browser does not support location.');
    const b=$('#mapNear'); b.disabled=true; b.textContent='Locating…';
    navigator.geolocation.getCurrentPosition(p=>{
      b.disabled=false; b.textContent='◎ Use my location';
      const lat=p.coords.latitude, lng=p.coords.longitude;
      choose({type:'me',label:'your location',lat,lng,note:inKenya(lat,lng)?'':'You appear to be outside Kenya, so these are the closest places we have.'});
    },err=>{ b.disabled=false; b.textContent='◎ Use my location'; showToast(err.code===1?'Location permission was denied. You can still search for a place.':'We could not get your location. Try searching for a place.'); },{enableHighAccuracy:false,timeout:10000,maximumAge:0});
  });
  radiusSel.addEventListener('change',paint);
  root.querySelectorAll('[data-kind]').forEach(b=>b.addEventListener('click',()=>{ kind=b.dataset.kind; root.querySelectorAll('[data-kind]').forEach(x=>{ const on=x===b; x.classList.toggle('on',on); x.setAttribute('aria-pressed',on); }); paint(); }));
  panel.addEventListener('click',e=>{ if(e.target.closest('[data-clear]')){ centre=null; input.value=''; paint(); } });

  paint();   // list renders immediately; the map follows once the library has loaded
  createMap($('#homeMap')).then(m=>{ map=m; layer=window.L.layerGroup().addTo(map); extra=window.L.layerGroup().addTo(map); paint(); })
    .catch(e=>{ $('#homeMap').innerHTML=`<p class="small" style="padding:20px">${esc(e.message)}</p>`; });
}

/* ---------- home page: featured stays + popular attractions (live from the store) ---------- */
function initHomeSections(){
  const stays=document.querySelector('#featuredStays'), attrs=document.querySelector('#popularAttractions'); if(!stays&&!attrs) return;
  const S=getListings().filter(l=>l.status==='approved').sort((a,b)=>smartScore(b).score-smartScore(a).score);
  if(stays){
    const top=[]; const seen=new Set();
    S.forEach(l=>{ const k=l.region||l.id; if(top.length<3 && !seen.has(k)){ seen.add(k); top.push(l); } }); S.forEach(l=>{ if(top.length<3 && !top.includes(l)) top.push(l); });
    stays.innerHTML=top.map(l=>{ const na=nearbyAttractions(l,100).length;
      return `<article class="hp-stay-card"><img src="${esc(coverOf(l))}" alt="${esc(l.name)}" /><div class="copy"><h3>${esc(l.name)}</h3><p>${esc(l.location)}</p><p>${esc(l.description.length>150?l.description.slice(0,147)+'…':l.description)}</p><a class="home-link" href="details.html?id=${encodeURIComponent(l.id)}">View stay</a>${na?`<a class="home-link" style="padding-top:4px" href="${nearHref(l,l.name,'attractions')}">${na} attraction${na===1?'':'s'} nearby →</a>`:''}</div></article>`; }).join('');
    document.querySelectorAll('[data-stays-count]').forEach(e=>e.textContent=`View all ${S.length} stays →`);
  }
  if(attrs){
    const A=getAttractions().filter(a=>a.status==='published'), pop=A.slice().sort((a,b)=>(!!b.featured-!!a.featured)||(nearbyStays(b).length-nearbyStays(a).length)).slice(0,3);
    const bg=(a,g)=>`background-image:linear-gradient(180deg,transparent ${g}%,rgba(0,0,0,.76)),url('${esc(coverOf(a))}')`;
    const links=a=>{ const n=nearbyStays(a).length; return `<a href="attraction-details.html?id=${encodeURIComponent(a.id)}">Explore ${esc(a.name)}</a>${hasGeo(a)?` <span class="hp-sep">·</span> <a href="${nearHref(a,a.name,'stays')}">${n} stay${n===1?'':'s'} nearby</a>`:''}`; };
    attrs.innerHTML=pop.length?`<article class="hp-attraction-feature" style="${bg(pop[0],42)};background-size:cover;background-position:center"><div class="copy"><div class="hp-kicker" style="color:#efb59e">${esc(pop[0].name)} · ${esc(pop[0].county)} County · ${esc(pop[0].category)}</div><h3>${esc(pop[0].tagline||'')}</h3><div class="hp-links">${links(pop[0])}</div></div></article><div class="hp-attraction-grid">${pop.slice(1).map(a=>`<article class="hp-attraction-card" style="${bg(a,15)}"><div class="copy"><div class="hp-kicker" style="color:#efb59e">${esc(a.category)} · ${esc(a.county)}</div><h3>${esc(a.tagline||'')}</h3><div class="hp-links">${links(a)}</div></div></article>`).join('')}</div>`:'<p class="small">No attractions are published yet.</p>';
    document.querySelectorAll('[data-attractions-count]').forEach(e=>e.textContent=`View all ${A.length} attractions →`);
  }
}

/* ---------- location pin picker for host / admin forms ---------- */
function initPickMaps(){
  document.querySelectorAll('[data-pick-map]').forEach(el=>{
    if(el.dataset.bound) return; el.dataset.bound='1';
    const form=el.closest('form')||document.body, latI=form.querySelector('[name=lat]'), lngI=form.querySelector('[name=lng]'), note=form.querySelector('[data-pick-note]');
    let map,marker;
    const setMarker=(lat,lng,zoom)=>{ if(!map) return; if(marker) marker.setLatLng([lat,lng]); else marker=window.L.marker([lat,lng],{icon:pinIcon('stay'),draggable:true}).addTo(map).on('dragend',()=>{ const p=marker.getLatLng(); store(p.lat,p.lng); }); if(zoom) map.setView([lat,lng],zoom); };
    const store=(lat,lng)=>{ latI.value=(+lat).toFixed(5); lngI.value=(+lng).toFixed(5); if(note) note.textContent=inKenya(lat,lng)?`Pinned at ${(+lat).toFixed(4)}, ${(+lng).toFixed(4)}`:'That point is outside Kenya. Please pin a place in Kenya.'; };
    const ready=createMap(el).then(m=>{
      map=m; map.on('click',e=>{ setMarker(e.latlng.lat,e.latlng.lng); store(e.latlng.lat,e.latlng.lng); });
      if(hasGeo({lat:latI.value,lng:lngI.value})) setMarker(+latI.value,+lngI.value,11);
    }).catch(e=>{ el.innerHTML=`<p class="small" style="padding:14px">${esc(e.message)}</p>`; });
    el.__pick={
      set:async(lat,lng)=>{ await ready; if(hasGeo({lat,lng})){ latI.value=lat; lngI.value=lng; setMarker(+lat,+lng,11); if(note) note.textContent=`Pinned at ${(+lat).toFixed(4)}, ${(+lng).toFixed(4)}`; } else { latI.value=''; lngI.value=''; if(marker){ map.removeLayer(marker); marker=null; } if(note) note.textContent='Click the map to pin the exact location.'; } },
      refresh:async()=>{ await ready; map&&map.invalidateSize(); },
      centreOn:async text=>{ await ready; const p=placeFor(text); if(p&&!marker&&map) map.setView([p.lat,p.lng],p.zoom); }
    };
    const loc=form.querySelector('[name=location],[name=county]'); if(loc) loc.addEventListener('change',()=>el.__pick.centreOn(loc.value+' '+((form.querySelector('[name=name]')||{}).value||'')));
    const here=form.querySelector('[data-pick-here]');
    if(here) here.addEventListener('click',()=>{ if(!navigator.geolocation) return showToast('Your browser does not support location.'); navigator.geolocation.getCurrentPosition(async p=>{ await ready; setMarker(p.coords.latitude,p.coords.longitude,14); store(p.coords.latitude,p.coords.longitude); },()=>showToast('We could not get your location. Click the map instead.'),{timeout:10000}); });
  });
}
/* read the pin from a form; falls back to a known place name, else keeps what the record already had */
function geoFromForm(form,text,existing){
  const lat=parseFloat(form.querySelector('[name=lat]')?.value), lng=parseFloat(form.querySelector('[name=lng]')?.value);
  if(Number.isFinite(lat)&&Number.isFinite(lng)){ if(!inKenya(lat,lng)) return {error:'The map pin is outside Kenya. Please pin a place in Kenya.'}; return {lat,lng,coordsSource:'pinned'}; }
  if(existing&&hasGeo(existing)) return {lat:+existing.lat,lng:+existing.lng,coordsSource:existing.coordsSource||'approx'};
  const p=placeFor(text); return p?{lat:p.lat,lng:p.lng,coordsSource:'approx'}:{};
}

/* ======================================================================
   MESSAGING — tourist ↔ host inbox (one conversation per guest + stay)
   Simple asynchronous inbox. Other open tabs refresh automatically; true
   real-time chat is listed as future work in the project document.
   ====================================================================== */
const MSG_MAX = 1000;
const getThreads = ()=>readJSON(ls,K.threads,[]);
const saveThreads = a=>putLS(K.threads,JSON.stringify(a));
const msgRole = ()=>{ const s=getSession(); return !s?null:s.role==='host'?'host':s.role==='tourist'?'guest':null; };
const lastT = t=>t.messages.length?t.messages[t.messages.length-1].t:t.created;
const unreadFor = (t,role)=>{ const read=(role==='guest'?t.readGuest:t.readHost)||0; return t.messages.filter(m=>m.from!==role && m.t>read).length; };
function myThreads(){ const s=getSession(), role=msgRole(); if(!s||!role) return []; return getThreads().filter(t=>role==='host'?t.hostId===s.id:t.guestId===s.id).sort((a,b)=>lastT(b)-lastT(a)); }
const unreadTotal = ()=>{ const role=msgRole(); return role?myThreads().reduce((n,t)=>n+unreadFor(t,role),0):0; };
function paintBadges(){ const n=unreadTotal(); document.querySelectorAll('[data-msg-badge]').forEach(b=>{ b.textContent=n>99?'99+':n; b.hidden=!n; }); }
const timeAgo = t=>{ const m=Math.round((Date.now()-t)/60000); if(m<1) return 'just now'; if(m<60) return m+' min ago'; const h=Math.round(m/60); if(h<24) return h+' h ago'; return new Date(t).toLocaleDateString('en-GB',{day:'2-digit',month:'short'}); };

function initMessages(){
  const root=document.querySelector('[data-messages]'); if(!root) return;
  const s=getSession(), role=root.dataset.role, me=role==='host'?'host':'guest', params=new URLSearchParams(location.search);
  let activeId=params.get('t')||'', draft=null, note='';

  /* a conversation that does not exist yet: opened from "Message host" / "Message guest" */
  const stayParam=params.get('stay'), guestParam=params.get('guest');
  if(!activeId && stayParam){
    const existing=getThreads().find(t=>t.stayId===stayParam && (me==='guest'?t.guestId===s.id:t.guestId===guestParam && t.hostId===s.id));
    if(existing) activeId=existing.id;
    else {
      const l=listingById(stayParam);
      if(!l) note='That stay could not be found.';
      else if(me==='guest'){ const host=getAccounts().find(a=>a.id===l.ownerId); if(!host) note='This stay\'s host has no account yet, so messaging is not available.'; else draft={stayId:l.id,stayName:l.name,guestId:s.id,guestName:s.name,hostId:host.id,hostName:host.name}; }
      else { const b=hostBookings().find(x=>x.stayId===stayParam&&x.guestId===guestParam); if(!b) note='You can message guests who have booked one of your stays.'; else draft={stayId:l.id,stayName:l.name,guestId:b.guestId,guestName:b.guestName,hostId:s.id,hostName:s.name}; }
    }
  }
  const otherName=t=>me==='guest'?t.hostName:t.guestName;
  const bubbleHTML=m=>`<div class="bubble ${m.from===me?'mine':'theirs'}"><div>${esc(m.text).replace(/\n/g,'<br>')}</div><span class="small">${timeAgo(m.t)}</span></div>`;

  const draw=()=>{
    const threads=myThreads(), cur=threads.find(t=>t.id===activeId)||null;
    if(cur){ const read=me==='guest'?'readGuest':'readHost'; if(unreadFor(cur,me)){ const all=getThreads(), t=all.find(x=>x.id===cur.id); t[read]=Date.now(); saveThreads(all); cur[read]=t[read]; } }
    const conv=cur||draft;
    const listHTML=(draft&&!cur?`<button type="button" class="thread on" data-draft><strong>${esc(otherName(draft))}</strong><span class="small">${esc(draft.stayName)}</span><span class="small"><em>New message</em></span></button>`:'')
      +threads.map(t=>{ const last=t.messages[t.messages.length-1], u=unreadFor(t,me); return `<button type="button" class="thread${cur&&cur.id===t.id?' on':''}" data-t="${esc(t.id)}"><strong>${esc(otherName(t))}${u?` <span class="msg-badge">${u}</span>`:''}</strong><span class="small">${esc(t.stayName)}</span><span class="small">${last?esc((last.from===me?'You: ':'')+last.text.slice(0,60)):''} · ${timeAgo(lastT(t))}</span></button>`; }).join('');
    const empty=!threads.length&&!draft;
    root.innerHTML=`<div class="msg-layout card"><div class="msg-list" role="list" aria-label="Conversations">${listHTML||''}${empty?`<div class="pad"><p class="small">No conversations yet.</p>${me==='guest'?'<a class="btn btn-sm" href="search.html">Find a stay to message</a>':'<p class="small">Guests who message you, and guests with bookings you start a chat with, appear here.</p>'}</div>`:''}</div>
      <div class="msg-pane">${conv?`<div class="msg-head"><div><strong>${esc(otherName(conv))}</strong><div class="small">About <a class="home-link" href="details.html?id=${encodeURIComponent(conv.stayId)}">${esc(conv.stayName)}</a></div></div></div><div class="msg-body" id="msgBody" tabindex="0" aria-live="polite">${conv.messages&&conv.messages.length?conv.messages.map(bubbleHTML).join(''):`<p class="small" style="text-align:center;margin:auto">Say hello to ${esc(otherName(conv))}. Ask about dates, the area or anything else.</p>`}</div><div class="msg-safe small">For your protection, keep payments and bookings on StayExplore. Never share card details or passwords in chat.</div><div class="msg-compose"><textarea id="msgText" rows="2" maxlength="${MSG_MAX}" placeholder="Write a message…" aria-label="Message"></textarea><button type="button" class="btn" id="msgSend">Send</button></div><div class="form-error" id="msgErr" role="alert" hidden></div>`:`<div class="pad" style="margin:auto;text-align:center"><p class="small">${esc(note)||'Select a conversation.'}</p></div>`}</div></div>`;
    const body=root.querySelector('#msgBody'); if(body) body.scrollTop=body.scrollHeight;
    paintBadges();
  };
  const send=()=>{
    const ta=root.querySelector('#msgText'), err=root.querySelector('#msgErr'), text=ta.value.trim();
    const fail=m=>{ err.textContent=m; err.hidden=false; };
    if(!text) return fail('Please write a message first.');
    if(text.length>MSG_MAX) return fail(`Messages can be up to ${MSG_MAX} characters.`);
    const all=getThreads(); let t=all.find(x=>x.id===activeId);
    if(!t){ if(!draft) return; t=Object.assign({id:'th-'+Date.now().toString(36),created:Date.now(),messages:[],readGuest:0,readHost:0},draft); all.push(t); activeId=t.id; draft=null; history.replaceState(null,'',location.pathname+'?t='+t.id); }
    t.messages.push({id:'m-'+Date.now().toString(36)+Math.random().toString(36).slice(2,5),from:me,text,t:Date.now()}); t[me==='guest'?'readGuest':'readHost']=Date.now();
    if(!saveThreads(all)) return fail(QUOTA_MSG);
    draw(); root.querySelector('#msgText').focus();
  };
  root.addEventListener('click',e=>{ const t=e.target.closest('[data-t]'); if(t){ activeId=t.dataset.t; draft=null; history.replaceState(null,'',location.pathname+'?t='+activeId); draw(); return; } if(e.target.closest('#msgSend')) send(); });
  root.addEventListener('keydown',e=>{ if(e.target.id==='msgText' && e.key==='Enter' && !e.shiftKey){ e.preventDefault(); send(); } });
  window.addEventListener('storage',e=>{ if(e.key===K.threads){ const ta=root.querySelector('#msgText'), keep=ta?ta.value:''; draw(); const n=root.querySelector('#msgText'); if(n&&keep) n.value=keep; } });
  draw();
  const ta=root.querySelector('#msgText'); if(ta&&(draft||activeId)) ta.focus();
}

/* ---------- admin: back up, move and reset the demo data (everything lives in this browser) ---------- */
function initBackup(){
  const root=document.querySelector('[data-backup]'); if(!root) return;
  const mine=()=>{ const keys=[]; for(let i=0;i<localStorage.length;i++){ const k=localStorage.key(i); if(k&&k.startsWith('stayexplore_')) keys.push(k); } return keys; };
  const dataKeys=()=>mine().filter(k=>k!==K.ses);
  const size=()=>{ let n=0; dataKeys().forEach(k=>n+=(localStorage.getItem(k)||'').length); return n; };
  const paint=()=>{ const kb=Math.round(size()/1024); root.querySelector('[data-backup-info]').textContent=`${getAccounts().length} accounts · ${getListings().length} listings · ${getAllBookings().length} bookings · about ${kb>1024?(kb/1024).toFixed(1)+' MB':kb+' KB'} stored in this browser`; };
  root.querySelector('[data-export]').addEventListener('click',()=>{
    const data={}; dataKeys().forEach(k=>data[k]=localStorage.getItem(k));
    const blob=new Blob([JSON.stringify({app:'stayexplore-kenya',version:1,exported:new Date().toISOString(),data})],{type:'application/json'});
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='stayexplore-backup-'+todayISO()+'.json'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),4000);
    showToast(`Backup downloaded (${Object.keys(data).length} items)`);
  });
  const file=root.querySelector('[data-import]');
  file.addEventListener('change',async()=>{
    const f=file.files&&file.files[0]; if(!f) return;
    try{
      const j=JSON.parse(await f.text());
      if(!j||j.app!=='stayexplore-kenya'||!j.data||typeof j.data!=='object') throw new Error('That file is not a StayExplore backup.');
      const keys=Object.keys(j.data); if(!keys.length||keys.some(k=>!k.startsWith('stayexplore_')||k===K.ses||typeof j.data[k]!=='string')) throw new Error('That backup file is damaged or not valid.');
      if(!confirm(`Restore this backup?\n\nIt replaces everything currently saved in this browser (${getAccounts().length} accounts, ${getListings().length} listings) with the backup's data. You will stay signed in as admin.`)){ file.value=''; return; }
      const old={}; dataKeys().forEach(k=>old[k]=localStorage.getItem(k));
      try{ dataKeys().forEach(k=>localStorage.removeItem(k)); keys.forEach(k=>localStorage.setItem(k,j.data[k])); }
      catch(e){ dataKeys().forEach(k=>localStorage.removeItem(k)); Object.keys(old).forEach(k=>{ try{ localStorage.setItem(k,old[k]); }catch{} }); throw new Error('This browser does not have enough storage for that backup. Nothing was changed.'); }
      setFlash('Backup restored. All accounts, listings and photos from the backup are now available.'); location.reload();
    }catch(e){ showToast(e.message||'The backup could not be restored.'); file.value=''; }
  });
  root.querySelector('[data-reset]').addEventListener('click',()=>{
    if(!confirm('Reset to the original sample data?\n\nThis deletes every account you created, plus all listings, photos, bookings, reviews, reports and messages saved in this browser. The built-in admin and sample accounts come back. This cannot be undone.')) return;
    dataKeys().forEach(k=>localStorage.removeItem(k)); setFlash('Sample data restored.'); location.reload();
  });
  paint();
}

/* ======================================================================
   TRUST & SECURITY (project document §1.4): EMAIL CONFIRMATION + HOST VERIFICATION
   A frontend cannot send real email, so confirmation messages are delivered to a
   "demo mailbox" that stands in for the user's inbox. Host verification is a real
   request-and-review workflow between host and administrator.
   ====================================================================== */
const getOutbox = ()=>readJSON(ls,K.outbox,[]);
const saveOutbox = a=>putLS(K.outbox,JSON.stringify(a));
const isEmailConfirmed = a=>!!a && a.emailVerified!==false;           // accounts created before this feature count as confirmed
const verifState = a=>!a?'unverified':(a.verification?a.verification.status:(a.sample?'verified':'unverified'));
const VERIF_LABEL = { unverified:'Not verified', pending:'Verification pending', verified:'Verified', rejected:'Verification rejected' };
const VERIF_BADGE = st=>`<span class="status ${st==='verified'?'success':st==='pending'?'warning':st==='rejected'?'danger':'info'}">${VERIF_LABEL[st]||st}</span>`;
const ownerOf = l=>getAccounts().find(a=>a.id===l.ownerId);
const ownerVerified = l=>verifState(ownerOf(l))==='verified';
const newToken = ()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');

function sendEmail(to,subject,text,link){
  const o=getOutbox(); o.unshift({id:'em-'+Date.now().toString(36)+Math.random().toString(36).slice(2,5),to:String(to).toLowerCase(),subject,text,link:link||null,t:Date.now()});
  saveOutbox(o.slice(0,200));
}
/* creates a fresh single-use link (valid 24 hours) and "sends" it. The caller saves the accounts list. */
function issueEmailToken(acc){
  acc.emailToken=newToken(); acc.emailTokenExp=Date.now()+24*3600*1000;
  sendEmail(acc.email,'Confirm your email address',`Hi ${firstName(acc.name)}, welcome to StayExplore Kenya! Please confirm your email address so you can ${acc.role==='host'?'list your properties':'book stays'}. This link works once and expires in 24 hours.`,{href:'confirm-email.html?token='+acc.emailToken,text:'Confirm my email address'});
}
function needEmailConfirmed(){
  const s=getSession(); if(!s||s.role==='admin') return '';
  return isEmailConfirmed(getAccounts().find(x=>x.id===s.id))?'':'Please confirm your email address first. Open your demo mailbox and click the confirmation link.';
}

/* ---------- confirm-email.html ---------- */
function initConfirmEmail(){
  const root=document.querySelector('[data-confirm-email]'); if(!root) return;
  const token=new URLSearchParams(location.search).get('token')||'', accs=getAccounts(), a=token?accs.find(x=>x.emailToken&&x.emailToken===token):null, s=getSession();
  const card=(title,body,actions='')=>{ root.innerHTML=`<div class="eyebrow">Email confirmation</div><h1>${title}</h1><div class="card pad" style="max-width:640px;margin-top:20px"><p>${body}</p><div class="actions" style="margin-top:16px">${actions}</div></div>`; };
  if(!a) return card('This link is not valid.','The confirmation link is invalid, or it has already been used. If you have not confirmed your email yet, open your demo mailbox and use the newest message, or request a new link there.','<a class="btn" href="mailbox.html">Open demo mailbox</a>');
  if(Date.now()>a.emailTokenExp) return card('This link has expired.','Confirmation links work for 24 hours. Open your demo mailbox and request a new link.','<a class="btn" href="mailbox.html">Open demo mailbox</a>');
  a.emailVerified=true; delete a.emailToken; delete a.emailTokenExp;
  if(!saveAccounts(accs)) return card('We could not save that.',QUOTA_MSG);
  const mine=s&&s.id===a.id;
  card('Email confirmed.',`Thank you, ${esc(firstName(a.name))}. <strong>${esc(a.email)}</strong> is confirmed. You can now ${a.role==='host'?'list properties and receive bookings':'book stays'}.`,mine?`<a class="btn" href="${routeForRole(a.role)}">Continue</a>`:'<a class="btn" href="login.html">Sign in</a>');
}

/* ---------- mailbox.html (stands in for the user's real inbox) ---------- */
function initMailbox(){
  const root=document.querySelector('[data-mailbox]'); if(!root) return;
  const s=getSession();
  if(!s){ root.innerHTML='<div class="card pad empty"><p class="small">Sign in to see the messages sent to your email address. <a class="home-link" href="login.html">Sign in</a></p></div>'; return; }
  const draw=()=>{
    const acc=getAccounts().find(x=>x.id===s.id)||s, mails=getOutbox().filter(m=>m.to===acc.email);
    root.innerHTML=`<div class="notice" style="margin-bottom:16px"><strong>Demo mailbox.</strong> A website prototype cannot send real email, so the messages StayExplore would send to <strong>${esc(acc.email)}</strong> appear here instead. In a live system they arrive in your normal inbox.</div>${!isEmailConfirmed(acc)?`<div class="notice" style="border-color:#e3b3ab;background:#fdf1ef;margin-bottom:16px"><strong>Your email is not confirmed yet.</strong> Open the message below and click the link. <button type="button" class="btn btn-outline btn-sm" data-resend style="margin-left:8px">Send a new link</button></div>`:''}
    <div class="grid">${mails.map(m=>`<article class="card pad"><div class="eyebrow">${longDate(m.t)} · ${esc(timeAgo(m.t))}</div><h3>${esc(m.subject)}</h3><p>${esc(m.text)}</p>${m.link?`<a class="btn btn-sm" href="${esc(m.link.href)}">${esc(m.link.text)}</a>`:''}</article>`).join('')||'<div class="card pad empty"><p class="small">No messages yet.</p></div>'}</div>`;
  };
  root.addEventListener('click',e=>{
    if(!e.target.closest('[data-resend]')) return; const accs=getAccounts(), a=accs.find(x=>x.id===s.id); if(!a||isEmailConfirmed(a)) return;
    issueEmailToken(a); if(saveAccounts(accs)){ draw(); showToast('A new confirmation link was sent to your demo mailbox. Older links no longer work.'); }
  });
  draw();
}

/* ---------- reminder banner ---------- */
function renderVerifyBanner(){
  const s=getSession(); if(!s||s.role==='admin'||document.querySelector('.verify-banner')||['mailbox.html','confirm-email.html'].includes(currentPage())) return;
  const a=getAccounts().find(x=>x.id===s.id); if(!a) return;
  const lines=[]; const file=currentPage();
  if(!isEmailConfirmed(a)) lines.push(`<strong>Confirm your email address</strong> to ${a.role==='host'?'list properties':'book stays'}. <a href="mailbox.html">Open demo mailbox</a>`);
  if(a.role==='host' && isEmailConfirmed(a) && ['unverified','rejected'].includes(verifState(a)) && ['host-dashboard.html','host-manage-listings.html','host-add-listing.html'].includes(file)) lines.push(`<strong>${verifState(a)==='rejected'?'Your verification was not approved.':'Your host identity is not verified yet.'}</strong> Verified hosts get a “✓ Verified host” badge and guests trust them more. <a href="host-profile.html">Verify now</a>`);
  if(!lines.length) return;
  const b=document.createElement('div'); b.className='verify-banner'; b.setAttribute('role','status'); b.innerHTML=lines.join('<br>');
  const target=document.querySelector('.page-head')||document.querySelector('main'); if(!target) return;
  if(target.matches('main')) target.prepend(b); else target.parentNode.insertBefore(b,target);
}

/* ---------- host: submit identity for verification ---------- */
function initHostVerify(){
  const root=document.querySelector('[data-verify]'); if(!root) return;
  const s=getSession(); bindPreviews();
  const draw=()=>{
    const acc=getAccounts().find(x=>x.id===s.id)||s, st=verifState(acc), v=acc.verification||{};
    const form=`<div class="grid" data-verify-form><div class="grid grid-2"><div class="field"><label>LEGAL NAME (AS ON YOUR ID)</label><input name="legalName" value="${esc(v.legalName||acc.name)}"></div><div class="field"><label>ID TYPE</label><select name="idType"><option>National ID</option><option>Passport</option></select></div></div><div class="grid grid-2"><div class="field"><label>ID NUMBER</label><input name="idNumber" maxlength="14" placeholder="e.g. 12345678" value="${esc(v.idNumber||'')}"></div><div class="field"><label>PROPERTY PROOF REFERENCE (OPTIONAL)</label><input name="businessRef" maxlength="60" placeholder="Title deed, lease, permit or business no." value="${esc(v.businessRef||'')}"></div></div><div class="field"><label>PHOTO OF YOUR ID</label><input type="file" name="idPhoto" accept="image/*"><span class="small">Only administrators can see this photo. It is used to check that you are a real person who can host.</span></div><label class="check"><input type="checkbox" name="consent"> I confirm the details are mine and correct.</label><div class="form-error" role="alert" hidden></div><button type="button" class="btn" data-verify-submit>${st==='rejected'?'Resubmit for verification':'Submit for verification'}</button></div>`;
    const body=st==='verified'?`<div class="notice"><strong>You are a verified host.</strong> ${v.decidedAt?'Verified on '+longDate(v.decidedAt)+'. ':''}Guests see a “✓ Verified host” badge on your listings.</div>`
      :st==='pending'?`<div class="notice"><strong>Request received.</strong> You submitted it on ${longDate(v.submitted)}. An administrator will review it and you will get a message in your demo mailbox.</div>`
      :`${st==='rejected'?`<div class="notice" style="border-color:#e3b3ab;background:#fdf1ef;margin-bottom:12px"><strong>Not approved.</strong> ${esc(v.reason||'Please check your details and try again.')}</div>`:'<p class="small" style="margin-bottom:12px">Verification helps protect guests and hosts. An administrator reviews your ID before your account is marked as verified.</p>'}${form}`;
    root.innerHTML=`<div class="card pad"><div class="eyebrow">Host verification</div><h2>${VERIF_BADGE(st)}</h2><div style="margin-top:12px">${body}</div></div>`;
  };
  root.addEventListener('click',async e=>{
    const btn=e.target.closest('[data-verify-submit]'); if(!btn) return;
    const box=root.querySelector('[data-verify-form]'), err=m=>{ const x=box.querySelector('.form-error'); x.textContent=m; x.hidden=false; };
    const v=n=>(box.querySelector(`[name="${n}"]`)||{}).value?.trim()||'';
    const legalName=v('legalName'), idNumber=v('idNumber').toUpperCase(), file=box.querySelector('[name=idPhoto]').files[0];
    if(legalName.length<3) return err('Please enter your full legal name.');
    if(!/^[A-Z0-9]{6,14}$/.test(idNumber)) return err('Enter your ID or passport number (6–14 letters and numbers, no spaces).');
    if(!file) return err('Please attach a clear photo of your ID.');
    if(!box.querySelector('[name=consent]').checked) return err('Please confirm the details are yours.');
    const nerr=needEmailConfirmed(); if(nerr) return err(nerr);
    let photo; try{ photo=await readImage(file,1000,.7); }catch(ex){ return err(ex.message); }
    const accs=getAccounts(), a=accs.find(x=>x.id===s.id); if(!a) return err('Account not found.');
    a.verification={status:'pending',legalName,idType:v('idType'),idNumber,businessRef:v('businessRef'),idPhoto:photo,submitted:Date.now()};
    if(!saveAccounts(accs)) return err(QUOTA_MSG);
    sendEmail(a.email,'We received your verification request','Thank you. An administrator will review your ID shortly. We will message you when it has been checked.');
    draw(); showToast('Verification request submitted');
  });
  draw();
}

/* ---------- admin: review host verification requests (called from initAdminUsers) ---------- */
function initVerifyReview(){
  const panel=document.querySelector('#verifyPanel'); if(!panel) return () => {};
  let current=null;
  const open=id=>{
    const a=getAccounts().find(x=>x.id===id); if(!a) return; current=id; const v=a.verification||{};
    panel.hidden=false;
    panel.querySelector('#vrBody').innerHTML=`<h2>${esc(a.name)} <span class="small">· ${esc(a.email)}</span></h2><p class="small">Submitted ${v.submitted?longDate(v.submitted):'—'} · ${VERIF_BADGE(verifState(a))}</p><div class="grid grid-2" style="margin-top:12px"><div><div class="kpi"><span class="small">LEGAL NAME</span><strong>${esc(v.legalName||'—')}</strong></div><div class="kpi" style="margin-top:8px"><span class="small">${esc((v.idType||'ID').toUpperCase())} NUMBER</span><strong>${esc(v.idNumber||'—')}</strong></div><div class="kpi" style="margin-top:8px"><span class="small">PROPERTY PROOF</span><strong>${esc(v.businessRef||'Not provided')}</strong></div><div class="kpi" style="margin-top:8px"><span class="small">LISTINGS</span><strong>${getListings().filter(l=>l.ownerId===a.id).length}</strong></div></div><div>${v.idPhoto?`<a href="${v.idPhoto}" target="_blank" rel="noopener" title="Open full size"><img class="id-photo" src="${v.idPhoto}" alt="ID photo submitted by ${esc(a.name)}"></a>`:'<p class="small">No photo attached.</p>'}</div></div>`;
    panel.querySelector('[name=reason]').value=''; panel.querySelector('.form-error').hidden=true;
    panel.querySelector('[data-vr-verify]').disabled=verifState(a)==='verified'; panel.scrollIntoView({behavior:'smooth',block:'start'});
  };
  const decide=ok=>{
    const err=m=>{ const x=panel.querySelector('.form-error'); x.textContent=m; x.hidden=false; };
    const reason=panel.querySelector('[name=reason]').value.trim();
    if(!ok && reason.length<5) return err('Please give the host a short reason so they can fix it.');
    const accs=getAccounts(), a=accs.find(x=>x.id===current); if(!a) return;
    a.verification=Object.assign({},a.verification,{status:ok?'verified':'rejected',decidedAt:Date.now(),decidedBy:getSession().name,reason:ok?'':reason});
    if(!putLS(K.acc,JSON.stringify(accs))) return err(QUOTA_MSG);
    sendEmail(a.email,ok?'Your host account is verified':'Your verification was not approved',ok?'Good news! Your identity has been checked and your host account is now verified. Guests will see a “Verified host” badge on your listings.':'We could not approve your verification. Reason: '+reason+' You can correct your details and submit again from your host profile.',{href:'host-profile.html',text:'Open host profile'});
    panel.hidden=true; showToast(ok?'Host verified':'Verification rejected'); window.dispatchEvent(new CustomEvent('se-users-changed'));
  };
  panel.querySelector('[data-vr-verify]').addEventListener('click',()=>decide(true));
  panel.querySelector('[data-vr-reject]').addEventListener('click',()=>decide(false));
  panel.querySelector('[data-vr-close]').addEventListener('click',()=>{ panel.hidden=true; });
  return open;
}

/* ---------- host / admin application shell ---------- */
function initShell(){
  const shell=document.querySelector('[data-shell]');
  if(!shell || shell.dataset.ready) return;
  const role=need, active=document.body.dataset.active||'', s=getSession();
  const host=[['dashboard','Host overview','host-dashboard.html'],['listings','Manage listings','host-manage-listings.html'],['add','Add listing','host-add-listing.html'],['availability','Availability','host-availability.html'],['bookings','Manage bookings','host-manage-bookings.html'],['messages','Messages','host-messages.html'],['payments','Payment tracking','host-payments.html'],['reviews','Guest reviews','host-reviews.html'],['incident','Report incident','host-report-incident.html'],['profile','Host profile','host-profile.html']];
  const admin=[['dashboard','Admin overview','admin-dashboard.html'],['approvals','Listing approvals','admin-listing-approvals.html'],['users','Manage users','admin-manage-users.html'],['reviews','Moderate reviews','admin-moderate-reviews.html'],['incidents','Incident reports','admin-incident-reports.html'],['attractions','Manage attractions','admin-attractions.html'],['profile','Admin profile','admin-profile.html']];
  const nav=(role==='host'?host:admin).map(([id,l,h])=>`<a href="${h}" ${active===id?'class="active" aria-current="page"':''}>${l}${id==='messages'?' <span class="msg-badge" data-msg-badge hidden></span>':''}</a>`).join('');
  const content=shell.innerHTML;                    // copy BEFORE replacing (avoids duplicated content)
  shell.dataset.ready='1';
  shell.innerHTML=`<aside class="app-sidebar"><a class="side-brand" href="index.html"><span class="brand-mark">SE</span><span>StayExplore<small>KENYA</small></span></a><div class="side-section">${role==='host'?'Hosting':'Administration'}</div><nav class="side-nav">${nav}</nav><div class="side-section">Account</div><nav class="side-nav"><a href="index.html">View website</a><a href="#" data-logout>Sign out</a></nav></aside><main class="app-main"><header class="app-top"><span class="user-chip">${esc(s?.name||roleLabel(role))}</span><span class="role-pill role-pill-dark">${roleLabel(role)}</span></header><div class="app-content">${content}</div></main>`;
}

/* ---------- auth forms ---------- */
function formMessage(form,text){
  let m=form.querySelector('.form-error');
  if(!m){ m=document.createElement('div'); m.className='form-error'; m.setAttribute('role','alert');
    const btn=form.querySelector('[type=submit]'), anchor=btn&&[...form.children].find(c=>c===btn||c.contains(btn));   // submit may be nested in a wrapper row
    form.insertBefore(m,anchor||null); }
  m.textContent=text; m.hidden=!text;
}
async function handleRegister(form){
  const f=n=>form.querySelector(`[name="${n}"]`);
  const name=f('name').value.trim(), email=f('email').value.trim().toLowerCase(), password=f('password').value, role=f('role').value;
  if(!['tourist','host'].includes(role)) return formMessage(form,'Administrator accounts cannot be created here.');
  const list=getAccounts();
  if(list.some(a=>a.email===email)) return formMessage(form,'An account with this email already exists. Please sign in instead.');
  const salt=newSalt();
  const account={id:'user-'+Date.now(),created:Date.now(),name,email,role,salt,hash:await sha(salt+password),emailVerified:false};
  if(role==='host') account.verification={status:'unverified'};
  issueEmailToken(account);
  list.push(account); saveAccounts(list); setSession(account);
  setFlash(`Welcome to StayExplore, ${name.split(' ')[0]}! Your ${roleLabel(role).toLowerCase()} account is ready. We sent a confirmation link to your demo mailbox.`);
  go(safeNext(role)||routeForRole(role));
}
async function handleLogin(form){
  await ensureDemoAdmin(); await ensureSampleHosts();
  const email=form.querySelector('[name="email"]').value.trim().toLowerCase();
  const password=form.querySelector('[name="password"]').value;
  const list=getAccounts(); const a=list.find(x=>x.email===email);
  let ok=false;
  if(a){
    if(a.hash) ok=(await sha(a.salt+password))===a.hash;
    else if(a.password!==undefined && a.password===password){ ok=true; a.salt=newSalt(); a.hash=await sha(a.salt+password); delete a.password; saveAccounts(list); } // upgrade legacy plain-text account
  }
  if(!ok) return formMessage(form,'Incorrect email or password. If you are new here, create an account first.');
  if(a.suspended) return formMessage(form,'This account has been suspended. Please contact support.');
  setSession(a);
  setFlash(`Welcome back, ${a.name.split(' ')[0]}.`);
  go(safeNext(a.role)||routeForRole(a.role));
}

/* ---------- bookings, drafts, favourites ---------- */
const money=n=>'KSh '+Math.round(n).toLocaleString('en-KE');
const fmtDate=d=>new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
const nightsBetween=(a,b)=>Math.round((new Date(b)-new Date(a))/864e5);
const getDraft=()=>readJSON(ss,K.draft,null);
function priceOf(d){ const n=nightsBetween(d.checkin,d.checkout), sub=n*d.price, fee=Math.round(sub*0.05); return {n,sub,fee,total:sub+fee}; }
const favKey=()=>{ const s=getSession(); return s?K.favs+s.id:null; };
const getFavs=()=>{ const k=favKey(); return k?readJSON(ls,k,[]):[]; };

function initDateInputs(){
  const today=new Date().toISOString().slice(0,10);
  const inn=document.querySelector('[data-draft="checkin"]'), out=document.querySelector('[data-draft="checkout"]');
  if(!inn||!out) return;
  const sync=()=>{ inn.min=today; const m=new Date(inn.value||today); m.setDate(m.getDate()+1); out.min=m.toISOString().slice(0,10); if(out.value && out.value<=inn.value) out.value=out.min; };
  inn.addEventListener('change',sync); sync();
}
function stayBrief(id){ const l=listingById(id); return l?{stayId:l.id,name:l.name,place:l.location,price:l.price,rating:String(l.rating||'')}:Object.assign({stayId:'karura-house'},STAYS['karura-house']); }
function readDraftFromPage(){
  const d=currentStay?Object.assign({},getDraft()||{},stayBrief(currentStay.id)):Object.assign({stayId:'karura-house'},STAYS['karura-house'],getDraft()||{}); d.stay=d.name;
  document.querySelectorAll('[data-draft]').forEach(el=>{ d[el.dataset.draft]=el.value; });
  return d;
}
function initBookingFlow(){
  const page=currentPage();
  const reserve=document.querySelector('[data-reserve]');
  if(reserve) reserve.addEventListener('click',e=>{
    const d=readDraftFromPage();
    if(!d.checkin||!d.checkout||nightsBetween(d.checkin,d.checkout)<1){ e.preventDefault(); showToast('Choose a check-out date after your check-in date.'); return; }
    const ne=needEmailConfirmed(); if(ne){ e.preventDefault(); showToast(ne); return; }
    const busy=stayConflict(d.stayId,d.checkin,d.checkout); if(busy){ e.preventDefault(); showToast(busy); return; }
    ss.set(K.draft,JSON.stringify(d));
  });
  if(page==='book.html'||page==='payment.html'){
    const saved=getDraft(); const d=Object.assign({stayId:'karura-house'},STAYS['karura-house'],saved||{});
    if(!saved){ d.checkin='2026-10-14'; d.checkout='2026-10-18'; d.guests='2 guests'; }
    document.querySelectorAll('[data-draft]').forEach(el=>{ if(d[el.dataset.draft]) el.value=d[el.dataset.draft]; });
    const paint=()=>{
      Object.assign(d,Object.fromEntries([...document.querySelectorAll('[data-draft]')].map(e=>[e.dataset.draft,e.value])));
      if(!d.checkin||!d.checkout||nightsBetween(d.checkin,d.checkout)<1) return;
      const p=priceOf(d), set=(k,v)=>document.querySelectorAll(`[data-bind="${k}"]`).forEach(e=>e.textContent=v);
      set('range',`${fmtDate(d.checkin)} – ${fmtDate(d.checkout)} · ${d.guests||'2 guests'}`);
      set('nightsline',`${p.n} night${p.n>1?'s':''} × ${money(d.price)}`); set('sub',money(p.sub)); set('fee',money(p.fee)); set('total',money(p.total)); set('stay',d.name);
      ss.set(K.draft,JSON.stringify(d));
    };
    document.querySelectorAll('[data-draft]').forEach(e=>e.addEventListener('change',paint)); paint();
  }
  if(page==='confirmation.html'){
    const s=getSession(), ref=new URLSearchParams(location.search).get('ref'); const b=s&&(ref?getAllBookings().find(x=>x.ref===ref&&x.guestId===s.id):readJSON(ls,K.last+s.id,null));
    if(b){ const set=(k,v)=>document.querySelectorAll(`[data-bind="${k}"]`).forEach(e=>e.textContent=v);
      set('ref',b.ref); set('checkin',fmtDate(b.checkin)); set('checkout',fmtDate(b.checkout)); set('stay',b.stay); set('paymethod',PAY_METHODS[b.payMethod]||'—'); set('amount',money(b.total));
      document.querySelectorAll('[data-pay-status]').forEach(e=>e.innerHTML=payBadge(b)); document.querySelectorAll('[data-pay-help]').forEach(e=>e.innerHTML=payHelp(b)); }
  }
}
function initPaymentPage(){
  const form=document.querySelector('#paymentForm'); if(!form) return;
  const hint=form.querySelector('#payHint'), refField=form.querySelector('[data-ref-field]'), HINTS={cash:'You pay the host in cash when you arrive. They mark it as paid and the status updates here.',bank:'After booking, the host sends you their bank details. Transfer the amount, then add your bank reference below if you like.',mpesa:'After booking, send the amount to the host by M-Pesa and enter the M-Pesa confirmation code below so they can match it.'};
  form.querySelectorAll('input[name=method]').forEach(r=>r.addEventListener('change',()=>{ hint.textContent=HINTS[r.value]; hint.hidden=false; refField.hidden=r.value==='cash'; form.querySelectorAll('.pay-method').forEach(l=>l.classList.toggle('on',l.contains(r)&&r.checked)); }));
}
function initFavourites(){
  document.querySelectorAll('[data-fav]').forEach(btn=>{
    const id=btn.dataset.fav, paint=()=>{ const on=getFavs().includes(id); btn.textContent=(on?'♥ Saved':btn.dataset.label||'♡ Save'); btn.setAttribute('aria-pressed',on); };
    paint();
    btn.addEventListener('click',e=>{
      e.preventDefault();
      const k=favKey(); if(!k){ requireLogin('Please sign in to save favourites.'); return; }
      let f=getFavs(); const on=f.includes(id); f=on?f.filter(x=>x!==id):[...f,id]; ls.set(k,JSON.stringify(f)); paint();
      showToast(on?'Removed from favourites':'Saved to favourites');
    });
  });
  const stays=document.querySelector('#favStays'), atts=document.querySelector('#favAttractions');
  if(!stays) return;
  const draw=()=>{
    const f=getFavs(), LS_=getListings(), AT_=getAttractions(), s=f.filter(i=>LS_.some(l=>l.id===i)), a=f.filter(i=>AT_.some(x=>x.id===i));
    stays.innerHTML=s.length?s.map(i=>{const x=LS_.find(l=>l.id===i);return `<article class="card"><div class="photo" style="${bgStyle(coverOf(x))}"></div><div class="pad"><h3>${esc(x.name)}</h3><div class="rating">${+x.rating?'★ '+(+x.rating).toFixed(1):'New listing'}</div><p class="small">${esc(x.location)} · ${money(x.price)}/night</p><div class="actions"><a class="btn btn-sm" href="details.html?id=${encodeURIComponent(i)}">View stay</a><button class="btn btn-outline btn-sm" type="button" data-unfav="${i}">Remove</button></div></div></article>`}).join(''):`<div class="card pad full empty"><div class="eyebrow">Saved stays</div><h2>No saved stays yet.</h2><p class="small">Tap “Save to favourites” on any stay to keep it here.</p><a class="btn btn-sm" href="search.html">Find a stay</a></div>`;
    atts.innerHTML=a.length?a.map(i=>{const x=AT_.find(t=>t.id===i);return `<article class="card"><div class="photo" style="${bgStyle(coverOf(x))}"></div><div class="pad"><h3>${esc(x.name)}</h3><p class="small">${esc(x.category)} · ${esc(x.county)}</p><div class="actions"><a class="btn btn-sm" href="attraction-details.html?id=${encodeURIComponent(i)}">View</a><button class="btn btn-outline btn-sm" type="button" data-unfav="${i}">Remove</button></div></div></article>`}).join(''):`<div class="card pad full empty"><div class="eyebrow">Saved attractions</div><h2>No saved attractions yet.</h2><p class="small">Explore destinations and save the ones you want to visit.</p><a class="btn btn-sm" href="search.html#attractions">Explore attractions</a></div>`;
    document.querySelectorAll('[data-unfav]').forEach(b=>b.addEventListener('click',()=>{ ls.set(favKey(),JSON.stringify(getFavs().filter(x=>x!==b.dataset.unfav))); draw(); showToast('Removed from favourites'); }));
  };
  draw();
}

/* ---------- search / filter / sort ---------- */
function initSearch(){
  const list=document.querySelector('#stayList'); if(!list) return;
  const $=s=>document.querySelector(s);
  const params=new URLSearchParams(location.search); let region=REGIONS[params.get('region')]?params.get('region'):'';
  const nm=(params.get('near')||'').split(',').map(Number); let near=nm.length===2&&nm.every(Number.isFinite)?{lat:nm[0],lng:nm[1],label:params.get('label')||'this place',r:Math.min(500,Math.max(5,+params.get('r')||100))}:null;
  list.innerHTML=getListings().filter(l=>l.status==='approved').map(l=>stayCardHTML(l,near)).join('');
  const cards=[...list.querySelectorAll('.filterable')];
  if(params.get('q')) $('#liveSearch').value=params.get('q');
  const note=document.createElement('div'); note.className='notice region-note'; note.hidden=true;
  list.parentElement.insertBefore(note,list);
  const paintNote=()=>{ note.hidden=!region&&!near; if(near) note.innerHTML=`Showing stays within <strong>${near.r} km</strong> of <strong>${esc(near.label)}</strong>, nearest first. <a href="#" data-clear-region class="home-link">Show all stays</a>`; else if(region) note.innerHTML=`Showing stays near <strong>${esc(REGIONS[region].name)}</strong>. <a href="#" data-clear-region class="home-link">Show all stays</a>`; };
  note.addEventListener('click',e=>{ if(e.target.closest('[data-clear-region]')){ e.preventDefault(); region=''; near=null; cards.forEach(c=>c.querySelectorAll('[style*="font-weight:700"]').forEach(x=>x.remove())); paintNote(); apply(); } });
  const apply=()=>{
    const q=($('#liveSearch').value||'').toLowerCase().trim();
    const min=parseFloat($('#minPrice').value)||0, max=parseFloat($('#maxPrice').value)||Infinity;
    const ratings=[...document.querySelectorAll('[data-min-rating]:checked')].map(c=>+c.dataset.minRating);
    const minRating=ratings.length?Math.min(...ratings):0;
    const amen=[...document.querySelectorAll('[data-amenity]:checked')].map(c=>c.dataset.amenity);
    let shown=0;
    cards.forEach(c=>{
      const ok=(!region||c.dataset.region===region) && (!near||(c.dataset.dist!==''&&+c.dataset.dist<=near.r)) && c.textContent.toLowerCase().includes(q) && +c.dataset.price>=min && +c.dataset.price<=max && +c.dataset.rating>=minRating && amen.every(a=>c.dataset.amenities.split(',').includes(a));
      c.hidden=!ok; if(ok) shown++;
    });
    const sort=$('#sortBy').value, key={Recommended:['score',-1],'Lowest price':['price',1],'Highest rated':['rating',-1]}[sort]||['score',-1];
    [...cards].sort((a,b)=>near&&sort==='Recommended'?((+a.dataset.dist||1e9)-(+b.dataset.dist||1e9)):(+a.dataset[key[0]]-+b.dataset[key[0]])*key[1]).forEach(c=>list.appendChild(c));
    window.__refreshSearchMap&&window.__refreshSearchMap(cards.filter(c=>!c.hidden));
    $('#resultCount').textContent=`${shown} stay${shown===1?'':'s'} found · smart score enabled`;
    $('#noResults').hidden=shown>0;
  };
  ['#liveSearch','#minPrice','#maxPrice'].forEach(s=>$(s).addEventListener('input',apply));
  ['#sortBy'].forEach(s=>$(s).addEventListener('change',apply));
  document.querySelectorAll('[data-min-rating],[data-amenity]').forEach(c=>c.addEventListener('change',apply));
  $('#applyFilters').addEventListener('click',apply);
  $('#resetFilters').addEventListener('click',()=>{ document.querySelectorAll('#filters input').forEach(i=>i.type==='checkbox'?i.checked=false:i.value=''); region=''; near=null; paintNote(); apply(); });
  /* map view of whatever the filters currently show */
  const mapBtn=$('#viewMap'), mapBox=document.createElement('div'); mapBox.id='searchMap'; mapBox.className='card search-map'; mapBox.hidden=true; mapBox.innerHTML='<div class="search-map-canvas" id="searchMapCanvas"></div>';
  list.parentElement.insertBefore(mapBox,list); let smap,slayer;
  window.__refreshSearchMap=cs=>{ if(!smap||mapBox.hidden) return; plot(smap,slayer,cs.filter(c=>c.dataset.lat!=='').map(c=>{ const l=listingById(c.dataset.id); return l?stayPin(l):null; }).filter(Boolean),true); };
  if(mapBtn) mapBtn.addEventListener('click',async()=>{
    mapBox.hidden=!mapBox.hidden; mapBtn.textContent=mapBox.hidden?'View map':'Hide map';
    if(mapBox.hidden) return;
    try{ if(!smap){ smap=await createMap($('#searchMapCanvas')); slayer=window.L.layerGroup().addTo(smap); } smap.invalidateSize(); window.__refreshSearchMap(cards.filter(c=>!c.hidden)); }
    catch(e){ $('#searchMapCanvas').innerHTML=`<p class="small" style="padding:20px">${esc(e.message)}</p>`; }
  });
  paintNote(); apply();
}

/* ---------- misc widgets ---------- */
function bindForms(){
  document.querySelectorAll('form').forEach(form=>{
    form.noValidate=false;
    form.addEventListener('submit',async e=>{
      e.preventDefault();
      if(!form.checkValidity()){ form.reportValidity(); return; }
      const btn=form.querySelector('[type=submit]'); if(btn?.disabled) return;
      if(btn){ btn.disabled=true; btn.dataset.label=btn.textContent; }
      try{
        formMessage(form,'');
        if(form.id==='registerForm') await handleRegister(form);
        else if(form.id==='loginForm') await handleLogin(form);
        else if(form.hasAttribute('data-profile-form')) await handleProfile(form);
        else if(form.id==='listingForm') await handleListing(form);
        else if(form.id==='incidentForm') await handleIncident(form);
        else if(form.id==='incidentAdminForm') window.__saveIncidentAdmin&&window.__saveIncidentAdmin();
        else if(form.id==='attractionForm') await handleAttraction(form);
        else {
          if(form.dataset.next==='confirmation.html'){ const err=completeBooking(); if(err){ formMessage(form,err); if(btn) btn.disabled=false; return; } }
          if(form.dataset.next==='payment.html'){ const d=getDraft()||{}; const ph=form.querySelector('[data-phone]'); if(ph){ d.phone=ph.value; ss.set(K.draft,JSON.stringify(d)); } }
          if(form.dataset.next){ go(form.dataset.next); return; }
          if(form.dataset.success){ showToast(form.dataset.success); }
        }
      } finally { if(btn && !redirecting && !form.dataset.next){ btn.disabled=false; } }
    });
  });
  document.querySelectorAll('[data-row-action]').forEach(b=>b.addEventListener('click',()=>{
    const row=b.closest('tr'), st=row&&row.querySelector('.status');
    if(st){ st.textContent=b.dataset.rowAction; st.className='status '+(b.dataset.rowClass||'success'); }
    b.disabled=true; b.textContent='Done'; showToast(b.dataset.toast||`${b.dataset.rowAction} successfully`);
  }));
  document.querySelectorAll('[data-toast]:not([data-row-action])').forEach(b=>b.addEventListener('click',()=>showToast(b.dataset.toast)));
}
function bindTabs(){
  document.querySelectorAll('[data-tab-group]').forEach(group=>{
    const buttons=[...group.querySelectorAll('.tab[data-target]')], panels=[...group.parentElement.querySelectorAll('[data-tab-panel]')];
    const activate=t=>{ buttons.forEach(b=>{const on=b.dataset.target===t;b.classList.toggle('active',on);b.setAttribute('aria-selected',on);}); panels.forEach(p=>p.hidden=p.dataset.tabPanel!==t); };
    buttons.forEach(b=>b.addEventListener('click',()=>{ activate(b.dataset.target); history.replaceState(null,'',b.dataset.target==='attractions'?'#attractions':location.pathname+location.search); renderChrome(); }));
    const fromHash=location.hash==='#attractions'&&buttons.some(b=>b.dataset.target==='attractions');
    activate(fromHash?'attractions':(buttons.find(b=>b.classList.contains('active'))||buttons[0]).dataset.target);
    window.addEventListener('hashchange',()=>{ activate(location.hash==='#attractions'?'attractions':buttons[0].dataset.target); renderChrome(); });
  });
}
function bindMisc(){
  document.querySelectorAll('[data-rating]').forEach(g=>{ const b=g.querySelectorAll('button'); b.forEach((x,i)=>x.addEventListener('click',()=>{ b.forEach((y,j)=>y.classList.toggle('active',j<=i)); g.dataset.value=i+1; })); });
  document.querySelectorAll('form').forEach(f=>{ const g=f.querySelector('[data-rating]'); if(g) f.addEventListener('submit',e=>{ if(!g.dataset.value){ e.stopImmediatePropagation(); e.preventDefault(); showToast('Please choose a star rating.'); } },true); });
  document.querySelectorAll('[data-action="change-photo"]').forEach(b=>b.addEventListener('click',()=>document.querySelector(b.dataset.input||'[data-photo-input]')?.click()));
  document.querySelectorAll('.calendar .day.available,.calendar .day.blank-open').forEach(d=>d.addEventListener('click',()=>d.classList.toggle('selected')));
  bindUser();
  const r=new URLSearchParams(location.search).get('role'), sel=document.querySelector('#registerForm [name=role]');
  if(sel && r==='host') sel.value='host';
}

/* ---------- boot ---------- */
document.addEventListener('DOMContentLoaded',async()=>{
  try{
    if(redirecting) return;
    ensureDemoAdmin(); ensureSampleHosts(); seedStore(); syncRatings();
    initShell(); renderChrome();
    initHomeSections(); initHomeMap(); initPickMaps();
    initSearch(); initAttractionList(); initStayPage(); initAttractionPage();
    initHostListings(); initHostEdit(); initHostDashboard(); initHostBookings(); initHostPayments(); initHostAvailability(); initTouristBookings();
    initAdminListings(); initAdminAttractions(); initAdminUsers(); initAdminDashboard();
    initReviewPage(); initHostReviews(); initAdminReviews(); initIncidentForm(); initAdminIncidents(); initMessages(); paintBadges(); initPaymentPage(); initBackup(); initConfirmEmail(); initMailbox(); initHostVerify(); renderVerifyBanner();
    bindTabs(); initDateInputs(); initProfile();
    initBookingFlow(); initFavourites(); bindPreviews(); initPhotoUpload(); bindForms(); bindMisc();
    const m=consumeFlash(); if(m) setTimeout(()=>showToast(m),120);
  } finally { reveal(); }
});
})();
