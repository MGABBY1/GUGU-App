/** All 30 districts of Rwanda, grouped by province (Intara → Akarere) */
export const RWANDA_PROVINCES: Record<string, string[]> = {
  Kigali: ['Gasabo', 'Kicukiro', 'Nyarugenge'],
  'Northern Province': ['Burera', 'Gakenke', 'Gicumbi', 'Musanze', 'Rulindo'],
  'Southern Province': [
    'Gisagara', 'Huye', 'Kamonyi', 'Muhanga', 'Nyamagabe', 'Nyanza', 'Nyaruguru', 'Ruhango',
  ],
  'Eastern Province': [
    'Bugesera', 'Gatsibo', 'Kayonza', 'Kirehe', 'Ngoma', 'Nyagatare', 'Rwamagana',
  ],
  'Western Province': [
    'Karongi', 'Ngororero', 'Nyabihu', 'Nyamasheke', 'Rubavu', 'Rusizi', 'Rutsiro',
  ],
};

/** Flat list of all 30 districts */
export const ALL_DISTRICTS: string[] = Object.values(RWANDA_PROVINCES).flat();

/**
 * Umurenge (sectors) for every Akarere (district).
 * Used for nearby neighbor filtering when buying/selling.
 */
export const RWANDA_SECTORS: Record<string, string[]> = {
  // Kigali
  Gasabo: ['Bumbogo', 'Gatsata', 'Jali', 'Gikomero', 'Gisozi', 'Jabana', 'Kinyinya', 'Ndera', 'Nduba', 'Remera', 'Kacyiru', 'Kimihurura', 'Kimironko', 'Nyarutarama'],
  Kicukiro: ['Gahanga', 'Gatenga', 'Gikondo', 'Kagarama', 'Kanombe', 'Kicukiro', 'Kigarama', 'Masaka', 'Niboye', 'Nyarugunga'],
  Nyarugenge: ['Gitega', 'Kanyinya', 'Kigali', 'Kimisagara', 'Mageragere', 'Muhima', 'Nyakabanda', 'Nyamirambo', 'Nyarugenge', 'Rwezamenyo'],
  // Northern
  Burera: ['Bungwe', 'Butaro', 'Cyanika', 'Cyeru', 'Gahunga', 'Gatebe', 'Gitovu', 'Kagogo', 'Kinoni', 'Kinyababa'],
  Gakenke: ['Busengo', 'Coko', 'Cyabingo', 'Gakenke', 'Gashenyi', 'Mugunga', 'Janja', 'Kamubuga', 'Karambo', 'Kivumu'],
  Gicumbi: ['Bukure', 'Bwisige', 'Byumba', 'Cyumba', 'Giti', 'Kaniga', 'Manyagiro', 'Miyove', 'Kageyo', 'Mukarange'],
  Musanze: ['Busogo', 'Cyuve', 'Gacaca', 'Gashaki', 'Gataraga', 'Kimonyi', 'Kinigi', 'Muhoza', 'Muko', 'Nkotsi'],
  Rulindo: ['Base', 'Burega', 'Bushoki', 'Buyoga', 'Cyinzuzi', 'Cyungo', 'Kinihira', 'Kisaro', 'Masoro', 'Mbogo'],
  // Southern
  Gisagara: ['Gikonko', 'Gishubi', 'Kansi', 'Kibirizi', 'Kigembe', 'Mamba', 'Muganza', 'Musha', 'Ndora', 'Save'],
  Huye: ['Gishamvu', 'Karama', 'Kigoma', 'Kinazi', 'Maraba', 'Mbazi', 'Mukura', 'Ngoma', 'Ruhashya', 'Rusatira'],
  Kamonyi: ['Gacurabwenge', 'Karama', 'Kayenzi', 'Kayumbu', 'Mugina', 'Musambira', 'Ngamba', 'Nyamiyaga', 'Rugalika', 'Rukoma'],
  Muhanga: ['Cyeza', 'Kabacuzi', 'Kibangu', 'Kiyumba', 'Muhanga', 'Mushishiro', 'Nyabinoni', 'Nyamabuye', 'Nyarusange', 'Rongi'],
  Nyamagabe: ['Buruhukiro', 'Cuanika', 'Gasaka', 'Gatare', 'Kaduha', 'Kamegeli', 'Kibirizi', 'Kibumbwe', 'Kitabi', 'Mbazi'],
  Nyanza: ['Busasamana', 'Busoro', 'Cyabakamyi', 'Kibirizi', 'Kigoma', 'Mukingo', 'Muyira', 'Ntyazo', 'Nyagisozi', 'Rwabicuma'],
  Nyaruguru: ['Busanze', 'Cyahinda', 'Kibeho', 'Kivu', 'Mata', 'Munini', 'Ngera', 'Ngoma', 'Nyabimata', 'Nyagisozi'],
  Ruhango: ['Bweramana', 'Byimana', 'Kabagali', 'Kinazi', 'Kinihira', 'Mbuye', 'Mwendo', 'Ntongwe', 'Ruhango', 'Rusatira'],
  // Eastern
  Bugesera: ['Gashora', 'Juru', 'Kamabuye', 'Mareba', 'Mayange', 'Musenyi', 'Mwogo', 'Ngeruka', 'Ntarama', 'Nyamata'],
  Gatsibo: ['Gasange', 'Gatsibo', 'Gitoki', 'Kabarore', 'Kageyo', 'Kiramuruzi', 'Kiziguro', 'Muhura', 'Murambi', 'Ngarama'],
  Kayonza: ['Gahini', 'Kabare', 'Kabarondo', 'Mukarange', 'Murama', 'Murundi', 'Mwiri', 'Ndego', 'Nyamirama', 'Rukara'],
  Kirehe: ['Gahara', 'Gatore', 'Kigarama', 'Kigina', 'Kirehe', 'Mahama', 'Mpanga', 'Musaza', 'Mushikiri', 'Nasho'],
  Ngoma: ['Gashanda', 'Jarama', 'Karembo', 'Kazo', 'Kibungo', 'Mugesera', 'Murama', 'Mutenderi', 'Remera', 'Rukira'],
  Nyagatare: ['Gatunda', 'Karangazi', 'Katabagemu', 'Kiyombe', 'Matimba', 'Mimuli', 'Mukama', 'Musheri', 'Nyagatare', 'Rukomo'],
  Rwamagana: ['Fumbwe', 'Gahengeri', 'Gishali', 'Karenge', 'Kigabiro', 'Muhazi', 'Munyaga', 'Munyiginya', 'Musha', 'Nyakaliro'],
  // Western
  Karongi: ['Bwishyura', 'Gashari', 'Gishyita', 'Gitesi', 'Mubuga', 'Murambi', 'Murundi', 'Mutuntu', 'Rubengera', 'Rugabano'],
  Ngororero: ['Bwira', 'Gatumba', 'Hindiro', 'Kabatwa', 'Kageyo', 'Kavumu', 'Matyazo', 'Muhanda', 'Muhororo', 'Ndaro'],
  Nyabihu: ['Bigogwe', 'Jenda', 'Jomba', 'Kabatwa', 'Karago', 'Kintobo', 'Mukamira', 'Muringa', 'Rambura', 'Rugera'],
  Nyamasheke: ['Bushekeri', 'Bushenge', 'Cyato', 'Gihombo', 'Kagano', 'Kanjongo', 'Karambi', 'Karengera', 'Kirimbi', 'Macuba'],
  Rubavu: ['Bugeshi', 'Busasamana', 'Cyanzarwe', 'Gisenyi', 'Kanama', 'Mudende', 'Nyakiriba', 'Nyamyumba', 'Nyundo', 'Rubavu'],
  Rusizi: ['Bugarama', 'Butare', 'Bweyeye', 'Gashonga', 'Giheke', 'Gihundwe', 'Gitambi', 'Kamembe', 'Muganza', 'Mururu'],
  Rutsiro: ['Boneza', 'Gihango', 'Kigeyo', 'Kivumu', 'Manihira', 'Mukura', 'Murunda', 'Musasa', 'Mushonyi', 'Nyabirasi'],
};

/** Map district → province */
export function provinceForDistrict(district: string): string {
  for (const [province, districts] of Object.entries(RWANDA_PROVINCES)) {
    if (districts.includes(district)) return province;
  }
  return 'Kigali';
}

/** Sectors (Imirenge) for a district */
export function sectorsForDistrict(district: string): string[] {
  return RWANDA_SECTORS[district] || [];
}
