# Fast Open CMP - WordPress Plugin

Consent Management Platform (CMP) zgodna z **IAB TCF 2.2** i **Google Additional Consent Mode**. Wtyczka WordPress do centralnego zarzadzania consent boxami dla wielu domen.

## Spis tresci

1. [Architektura](#architektura)
2. [Struktura plikow](#struktura-plikow)
3. [Baza danych](#baza-danych)
4. [REST API](#rest-api)
5. [Panel administracyjny](#panel-administracyjny)
6. [CMP SDK (Frontend)](#cmp-sdk-frontend)
7. [Konfiguracja](#konfiguracja)
8. [Listy Vendorow](#listy-vendorow)
9. [Ciasteczka (Cookies)](#ciasteczka-cookies)
10. [Import danych IAB/Google](#import-danych-iabgoogle)
11. [Budowanie SDK](#budowanie-sdk)
12. [Rozwiazywanie problemow](#rozwiazywanie-problemow)

---

## Architektura

```
+------------------+     REST API      +-------------------+
|   Strona www     | <--------------> |  WordPress Plugin |
|   (SDK JS)       |                  |   (fast-open-cmp) |
+------------------+                  +-------------------+
                                              |
                                              v
                                      +------------------+
                                      |   MySQL (WP)     |
                                      |  - vendors       |
                                      |  - vendor_lists  |
                                      |  - purposes      |
                                      |  - templates     |
                                      |  - domains       |
                                      +------------------+
```

### Przepyw danych

1. **Uzytkownik** wchodzi na strone z SDK
2. **SDK** pobiera konfiguracje z `/wp-json/fast-open-cmp/v1/cmp-config?domain_id=X`
3. **SDK** pobiera vendor list z `/wp-json/fast-open-cmp/v1/vendor-list`
4. **SDK** wyswietla consent box (3 warstwy UI)
5. **Uzytkownik** wybiera zgody
6. **SDK** zapisuje zgody w ciasteczka: `eupubconsent-v2` (TC String) i `custom-vendors-consent` (kompaktowy string z ID vendorow)

---

## Struktura plikow

```
fast-open-cmp/
├── fast-open-cmp.php              # Glowny plik wtyczki (singleton)
├── README.md                      # Ta dokumentacja
│
├── includes/
│   ├── class-activator.php        # Aktywacja: tworzenie tabel, domyslne opcje
│   ├── class-deactivator.php      # Deaktywacja
│   ├── class-admin.php            # Panel admina: menu, AJAX, save settings
│   ├── class-api.php              # REST API: endpointy, budowanie konfiguracji
│   ├── class-cache.php            # Cache: WordPress Transients
│   ├── class-importer.php         # Import: GVL, Google ATP, purposes
│   └── class-polylang.php         # Integracja z Polylang (wielojezykowosc)
│
├── admin/
│   ├── views/
│   │   ├── dashboard.php          # Przeglad
│   │   ├── companies.php          # Firmy (corki)
│   │   ├── domains.php            # Domeny
│   │   ├── templates.php          # Szablony consent box
│   │   ├── vendor-lists.php       # Listy vendorow (per-template)
│   │   ├── vendors-iab.php        # Partnerzy IAB
│   │   ├── vendors-google.php     # Partnerzy Google
│   │   ├── vendors-custom.php     # Inni partnerzy
│   │   ├── reconsents.php         # Historia reconsentow
│   │   └── settings.php           # Ustawienia globalne
│   ├── css/admin.css              # Style admina
│   └── js/admin.js                # JavaScript admina
│
├── assets/
│   └── js/
│       └── cmp-sdk.js             # Zbudowany SDK (z cmp/app/dist)
│
└── languages/                     # Tlumaczenia wtyczki (i18n)
```

### Powiazany projekt CMP (zrodlo SDK)

```
../cmp/
├── app/                           # Frontend SDK (TypeScript + Vite)
│   ├── src/
│   │   ├── index.ts               # Entry point
│   │   ├── components/
│   │   │   ├── ConsentManager.ts  # Glowna klasa zarzadzania UI
│   │   │   ├── layers/
│   │   │   │   ├── FirstLayer.ts  # Warstwa 1 (banner)
│   │   │   │   ├── SecondLayer.ts # Warstwa 2 (cele)
│   │   │   │   └── ThirdLayer.ts  # Warstwa 3 (partnerzy)
│   │   │   ├── items/
│   │   │   │   ├── Purpose.ts     # Renderowanie celu
│   │   │   │   ├── Stack.ts       # Renderowanie stacka
│   │   │   │   └── Vendor.ts      # Renderowanie vendora
│   │   │   └── events/
│   │   │       └── EventHandlers.ts
│   │   ├── services/
│   │   │   ├── api.ts             # Fetch konfiguracji
│   │   │   ├── consent.ts         # Zarzadzanie stanem zgod
│   │   │   ├── cookie.ts          # Obsluga ciasteczek
│   │   │   ├── tcString.ts        # Generowanie TC String (IAB)
│   │   │   ├── cmpApi.ts          # IAB __tcfapi
│   │   │   └── customVendors.ts   # Google/Other vendors
│   │   └── models/
│   │       └── types.ts           # TypeScript interfaces
│   ├── dist/                      # Zbudowany SDK
│   └── vite.config.ts             # Konfiguracja Vite
│
└── api/                           # (Nieuzywane - zastaione przez WordPress API)
```

---

## Baza danych

Wtyczka tworzy nastepujace tabele (prefix: `wp_cmp_`):

### `wp_cmp_companies` - Firmy (corki)
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | ID firmy |
| name | VARCHAR(255) | Nazwa firmy |
| description | TEXT | Opis |
| created_at | DATETIME | Data utworzenia |
| updated_at | DATETIME | Data aktualizacji |

### `wp_cmp_domains` - Domeny
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | **DOMAIN_ID** (uzywane w API) |
| company_id | BIGINT FK | FK do companies |
| domain | VARCHAR(255) | np. "example.com" |
| prod_template_id | BIGINT FK | Szablon produkcyjny |
| test_template_id | BIGINT FK | Szablon testowy |
| current_reconsent_id | BIGINT FK | Aktywny reconsent |

### `wp_cmp_templates` - Szablony consent box
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | ID szablonu |
| name | VARCHAR(255) | Nazwa |
| language | VARCHAR(10) | Kod jezyka (pl, en, de, fr) |
| vendor_list_id | BIGINT FK | FK do vendor_lists (NULL = wszystkie wlaczone) |
| config | LONGTEXT | **JSON z konfiguracja** |

### `wp_cmp_reconsents` - Historia reconsentow
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | ID reconsentu |
| domain_id | BIGINT FK | FK do domains |
| title | VARCHAR(255) | Tytul |
| description | TEXT | Powod reconsentu |
| reconsent_timestamp | BIGINT | **Timestamp (reconsentID)** |

### `wp_cmp_vendors_iab` - Partnerzy IAB
| Kolumna | Typ | Opis |
|---------|-----|------|
| vendor_id | INT UNIQUE | ID vendora z GVL |
| name | VARCHAR(255) | Nazwa |
| data | LONGTEXT | JSON z pelnym obiektem vendora |
| is_enabled | TINYINT | 1=aktywny, 0=wylaczony globalnie |

### `wp_cmp_vendors_google` - Partnerzy Google
| Kolumna | Typ | Opis |
|---------|-----|------|
| provider_id | INT UNIQUE | ID z Google ATP CSV |
| provider_name | VARCHAR(255) | Nazwa |
| privacy_url | VARCHAR(500) | URL polityki prywatnosci |
| is_enabled | TINYINT | 1=aktywny |

### `wp_cmp_vendors_custom` - Inni partnerzy
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | ID |
| name | VARCHAR(255) | Nazwa |
| privacy_url | VARCHAR(500) | URL polityki |
| category | VARCHAR(50) | Kategoria (default: 'other') |
| is_enabled | TINYINT | 1=aktywny |

### `wp_cmp_vendor_lists` - Listy vendorow
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | ID listy |
| name | VARCHAR(255) | Nazwa listy |
| description | TEXT | Opis |
| created_at | DATETIME | Data utworzenia |
| updated_at | DATETIME | Data aktualizacji |

### `wp_cmp_vendor_list_entries` - Wpisy na listach vendorow
| Kolumna | Typ | Opis |
|---------|-----|------|
| id | BIGINT PK | ID wpisu |
| vendor_list_id | BIGINT FK | FK do vendor_lists |
| vendor_id | INT | ID vendora |
| vendor_type | ENUM | 'iab', 'google', 'custom' |
| is_enabled | TINYINT | 1=wlaczony na liscie, 0=wylaczony |

### `wp_cmp_purposes_iab` - Cele IAB (tlumaczenia)
| Kolumna | Typ | Opis |
|---------|-----|------|
| purpose_id | INT | ID celu (1-11) |
| language | VARCHAR(10) | Kod jezyka |
| data | LONGTEXT | JSON z tekstami |

### `wp_cmp_gvl_metadata` - Metadane GVL
| Kolumna | Typ | Opis |
|---------|-----|------|
| meta_key | VARCHAR(100) | Klucz (stacks, features, specialPurposes, etc.) |
| language | VARCHAR(10) | Kod jezyka |
| data | LONGTEXT | JSON z danymi |

---

## REST API

**Namespace:** `fast-open-cmp/v1`

### GET `/wp-json/fast-open-cmp/v1/cmp-config`

Pobiera konfiguracje CMP dla domeny.

**Parametry:**
| Parametr | Typ | Wymagany | Opis |
|----------|-----|----------|------|
| domain_id | int | Tak* | ID domeny |
| domainId | int | Tak* | Alternatywna nazwa (camelCase) |
| domain | string | Tak* | Nazwa domeny (zamiast ID) |
| env | string | Nie | 'prod' lub 'test' (default: 'prod') |

*Wymagany jeden z: domain_id, domainId, domain

**Response:**
```json
{
  "language": "pl",
  "cmpId": 28,
  "cmpVersion": 1,
  "tcfPolicyVersion": 5,
  "vendorListVersion": 69,
  "publisherCountryCode": "PL",
  "isServiceSpecific": true,
  "cookieName": "eupubconsent-v2",
  "cookieExpirationDays": 180,
  "reconsentID": 1751834182907,
  "texts": {
    "firstLayer": { "title": "...", "description": "...", ... },
    "secondLayer": { ... },
    "thirdLayer": { ... },
    "common": { ... }
  },
  "customConsents": [],
  "vendors": {
    "custom": {
      "google": { "123": { "id": 123, "name": "...", "urls": [...] } },
      "other": { "1": { "id": 1, "name": "...", "urls": [...] } }
    }
  },
  "purposesMetadata": { "vendorCounts": { "1": { "purposes": 100, "legIntPurposes": 50 }, ... } },
  "gvlUrl": "https://example.com/wp-json/fast-open-cmp/v1/vendor-list?lang=pl"
}
```

### GET `/wp-json/fast-open-cmp/v1/vendor-list`

Pobiera Global Vendor List (GVL) w formacie IAB.

**Parametry:**
| Parametr | Typ | Opis |
|----------|-----|------|
| lang | string | Jezyk (default: 'pl') |

**Response:** Pelny obiekt GVL z vendors, purposes, stacks, features, etc.

### GET `/wp-json/fast-open-cmp/v1/vendor-list/vendor-list.json`

Alias dla `/vendor-list` (kompatybilnosc z biblioteka @iabtcf/core GVL).

### GET `/wp-json/fast-open-cmp/v1/vendor-list/purposes-{lang}.json`

Pobiera tlumaczenia celow dla danego jezyka (kompatybilnosc z @iabtcf/core GVL.changeLanguage).

**Parametry URL:**
- `{lang}` - kod jezyka (pl, en, de, fr)

### GET `/wp-json/fast-open-cmp/v1/purposes-{lang}`

Alias dla purposes z opcjonalnym `.json`.

### GET `/wp-json/fast-open-cmp/v1/sdk`

Zwraca SDK JavaScript z wstrzyknieta konfiguracja.

**Parametry:**
| Parametr | Typ | Wymagany | Opis |
|----------|-----|----------|------|
| domain_id | int | Tak | ID domeny |
| env | string | Nie | 'prod' lub 'test' |

**Response:** `Content-Type: application/javascript`

---

## Panel administracyjny

### Menu glowne: "Fast Open CMP"

1. **Dashboard** - Przeglad, statystyki
2. **Firmy** - CRUD firm (organizacji-matek dla domen)
3. **Domeny** - CRUD domen, przypisanie szablon prod/test
4. **Szablony** - CRUD szablonow consent box, edycja tekstow, przypisanie listy vendorow
5. **Listy Vendorow** - Zarzadzanie zestawami vendorow per szablon (CRUD, klonowanie)
6. **Partnerzy IAB** - Lista zaimportowanych vendorow, globalny wlacz/wylacz
7. **Partnerzy Google** - Lista zaimportowanych z ATP CSV, globalny wlacz/wylacz
8. **Inni Partnerzy** - CRUD wlasnych partnerow
9. **Reconsenty** - Historia, wymuszanie reconsentu
10. **Ustawienia** - Globalne ustawienia CMP

### Ustawienia (`/wp-admin/admin.php?page=focmp-settings`)

| Opcja | Domyslnie | Opis |
|-------|-----------|------|
| CMP ID | 28 | ID CMP zarejestrowane w IAB |
| Wersja CMP | 1 | Wersja CMP |
| TCF Policy Version | 5 | TCF 2.2 = 5 |
| Kod kraju | PL | ISO 3166-1 alpha-2 |
| Domyslny jezyk | pl | pl, en, de, fr |
| Nazwa cookie | eupubconsent-v2 | Standardowa nazwa TCF |
| Waznosc cookie (dni) | 180 | Czas zycia cookie |
| Wlaczone stacki | 42 | ID stackow (przecinkami) |
| TTL cache (sek) | 60 | Czas zycia cache |
| GVL URL | vendor-list.consensu.org | URL do importu GVL |
| Google ATP URL | storage.googleapis.com | URL do importu CSV |

---

## CMP SDK (Frontend)

### Integracja na stronie

**Sposob 1: Dynamiczny SDK (zalecany)**
```html
<script src="https://twoja-strona.pl/wp-json/fast-open-cmp/v1/sdk?domain_id=1"></script>
```

**Sposob 2: Statyczny SDK + inicjalizacja**
```html
<script src="https://twoja-strona.pl/wp-content/plugins/fast-open-cmp/assets/js/cmp-sdk.js"></script>
<script>
  window.OpenCMP && window.OpenCMP.init('example.com', 1, 'https://twoja-strona.pl/wp-json/fast-open-cmp/v1');
</script>
```

### IAB __tcfapi

SDK implementuje standardowe IAB CMP API:

```javascript
// Sprawdzenie czy CMP jest gotowe
__tcfapi('addEventListener', 2, (tcData, success) => {
  if (tcData.eventStatus === 'tcloaded' || tcData.eventStatus === 'useractioncomplete') {
    console.log('TC String:', tcData.tcString);
    console.log('GDPR applies:', tcData.gdprApplies);
  }
});

// Pobranie TC Data
__tcfapi('getTCData', 2, (tcData, success) => {
  if (success) {
    console.log('Purpose 1 consent:', tcData.purpose.consents[1]);
  }
});

// Usuwanie listenera
__tcfapi('removeEventListener', 2, (success) => {}, listenerId);
```

### Otwieranie consent box programowo

```javascript
// Otwiera consent box (layer 1)
window.OpenCMP && window.OpenCMP.showConsentBox();

// Lub przez __tcfapi
__tcfapi('showConsentBox', 2, () => {});
```

### Warstwy UI

1. **Layer 1 (FirstLayer)** - Banner z przyciskami "Akceptuje" / "Ustawienia zaawansowane"
2. **Layer 2 (SecondLayer)** - Lista celow i stackow z checkboxami
3. **Layer 3 (ThirdLayer)** - Lista wszystkich partnerow (IAB, Google, Inni)

---

## Konfiguracja

### Struktura template.config (JSON)

```json
{
  "texts": {
    "firstLayer": {
      "title": "Zarzadzanie prywatnoscią",
      "description": "Strona uzywa plikow cookies...",
      "partnersLinkText": "Lista Zaufanych Partnerow",
      "settingsButtonText": "Ustawienia zaawansowane",
      "acceptAllButtonText": "Akceptuje"
    },
    "secondLayer": {
      "title": "Ustawienia zaawansowane",
      "description": "...",
      "partnersLinkText": "Lista Zaufanych Partnerow",
      "backButtonText": "wstecz",
      "preferencesTitle": "Zarzadzaj preferencjami zgody",
      "confirmButtonText": "Potwierdzam moje wybory",
      "rejectButtonText": "Nie akceptuje",
      "acceptAllButtonText": "Akceptuje bez zmian"
    },
    "thirdLayer": {
      "title": "Lista zaufanych partnerow",
      "description": "...",
      "backButtonText": "wstecz",
      "confirmButtonText": "Potwierdzam moje wybory",
      "rejectButtonText": "Nie akceptuje",
      "acceptAllButtonText": "Akceptuje bez zmian",
      "privacyPolicyText": "Wyswietl polityke prywatnosci",
      "legitimateInterestText": "Wyswietl oswiadczenie...",
      "alwaysActiveText": "Zawsze aktywne",
      "vendorGroups": {
        "iab": "Zaufani Partnerzy IAB",
        "google": "Partnerzy Google",
        "other": "Inni partnerzy"
      }
    },
    "common": {
      "exampleUseText": "Przyklad uzycia",
      "objectButtonText": "Zglos sprzeciw..."
    }
  },
  "customConsents": []
}
```

### Stacki IAB

Stack to grupowanie celow IAB w jeden checkbox. Domyslnie wlaczony tylko **stack 42**:

| Stack ID | Cele | Opis |
|----------|------|------|
| 42 | 2-10 | Reklamy spersonalizowane, tresci, pomiary |

Aby wyswietlic wszystkie 11 celow osobno, zostaw pole "Wlaczone stacki" puste.

---

## Listy Vendorow

### Koncepcja

Vendor List to samodzielny byt — zestaw vendorow (IAB, Google, Custom) z wlasnym stanem `is_enabled` per vendor. Szablon (template) wskazuje na Vendor List przez kolumne `vendor_list_id`. Wiele szablonow moze wspoldzielic jedna liste. Listy mozna klonowac niezaleznie.

```
Template A ──> Vendor List "PL Full"     (1200 IAB, 2000 Google)
Template B ──> Vendor List "PL Minimal"  (50 IAB, 100 Google)
Template C ──> NULL                      (wszystkie wlaczone globalnie)
```

### 2-poziomowe filtrowanie

Vendor jest widoczny w konfiguracji CMP gdy:

```
widoczny = (global is_enabled = 1) AND (lista is_enabled = 1 LUB brak listy)
```

| Szablon ma liste? | Global ON | Lista ON | Wynik |
|-------------------|-----------|----------|-------|
| Nie (NULL) | Tak | — | **Widoczny** |
| Nie (NULL) | Nie | — | Ukryty |
| Tak | Tak | Tak | **Widoczny** |
| Tak | Tak | Nie | Ukryty |
| Tak | Nie | Tak | Ukryty |
| Tak | Nie | Nie | Ukryty |

- **Globalny `is_enabled`** (tabele `vendors_iab`, `vendors_google`, `vendors_custom`) = master switch. Wylaczony globalnie = wylaczony wszedzie, niezaleznie od list.
- **`vendor_list_id = NULL`** w szablonie = kompatybilnosc wsteczna — wszystkie globalnie wlaczone vendory sa widoczne.

### Panel admina — Listy Vendorow

**URL:** `/wp-admin/admin.php?page=focmp-vendor-lists`

#### Widok listy
Tabela z kolumnami: ID, Nazwa, Opis, Liczba szablonow uzywajacych, Data utworzenia, Akcje (Edytuj, Klonuj, Usun).

#### Widok edycji
- Pola: Nazwa, Opis
- 3 zakladki: **IAB** | **Google** | **Custom**
- Kazda zakladka: tabela vendorow z checkboxami (lista is_enabled), badge globalnego statusu (ON/OFF), wyszukiwarka, przyciski "Zaznacz/Odznacz wszystko"
- Globalnie wylaczeni vendorzy (badge OFF) maja zablokowany checkbox

#### Tworzenie nowej listy
1. Uzytkownik podaje nazwe i opis
2. System tworzy liste i automatycznie wypelnia `vendor_list_entries` wpisami dla **wszystkich globalnie wlaczonych** vendorow z `is_enabled = 1`
3. Redirect na strone edycji — uzytkownik moze odznaczac vendorow

#### Klonowanie listy
1. Kopiuje `vendor_lists` row z nazwa + " (kopia)"
2. Kopiuje **wszystkie** `vendor_list_entries` z oryginalnym stanem `is_enabled`
3. Nowa lista jest niezalezna od oryginalu

#### Usuwanie listy
1. Szablony wskazujace na ta liste dostaja `vendor_list_id = NULL` (powrot do domyslnych)
2. Wszystkie `vendor_list_entries` sa usuwane
3. Lista jest usuwana

### Przypisanie listy do szablonu

W edycji szablonu (`/wp-admin/admin.php?page=focmp-templates&action=edit&id=X`) pojawia sie dropdown:

```
Lista vendorow: [-- Domyslna (wszystkie wlaczone) --] [v]  [Edytuj liste] [Nowa lista]
```

Opcje: "Domyslna" (NULL) + wszystkie istniejace listy. Linki skrotowe do edycji wybranej listy i tworzenia nowej.

### Wplyw na REST API

#### `/cmp-config` — vendor filtering
Endpoint `cmp-config` laduje `vendor_list_id` z szablonu przypisanego do domeny. Jesli ustawiony, filtruje vendorow zwracanych w:
- `vendors.custom.google` — tylko vendorzy z `is_enabled = 1` na liscie
- `vendors.custom.other` — j.w.
- `purposesMetadata.vendorCounts` — liczy tylko vendorow widocznych na liscie

#### `/vendor-list` — BEZ filtrowania
Endpoint `vendor-list` (GVL) zwraca **wszystkich** globalnie wlaczonych vendorow IAB. NIE filtruje per-lista — to globalny GVL uzywany przez SDK do dekodowania TC String.

### Techniczne szczegoly zapisu formularza

Formularz list vendorow uzywa kompaktowej metody zapisu zamiast indywidualnych checkboxow, aby uniknac limitu PHP `max_input_vars` (domyslnie 1000):

1. Checkboxy w formularzu **nie maja atrybutu `name`** — sa czysto wizualne
2. JavaScript przy `submit` zbiera ID **odznaczonych** vendorow do 3 ukrytych pol:
   - `disabled_iab` = "12,45,789" (przecinki)
   - `disabled_google` = "100,200"
   - `disabled_custom` = "3"
3. Backend parsuje te stringi i ustawia `is_enabled = 0` dla wymienionych, `is_enabled = 1` dla reszty

To redukuje liczbe pol POST z tysiecy do kilku, niezaleznie od liczby vendorow.

### Automatyczna migracja bazy danych

Przy aktualizacji pluginu (zmiana `FOCMP_VERSION`) migracja odpala sie automatycznie na `admin_init`:

1. `FOCMP_Activator::maybe_upgrade()` porownuje `focmp_version` z bazy z `FOCMP_VERSION`
2. Jesli rozne — uruchamia `create_tables()` (dbDelta — idempotentne)
3. Aktualizuje wersje w bazie

Tworzone sa nowe tabele `cmp_vendor_lists` i `cmp_vendor_list_entries`, kolumna `vendor_list_id` dodawana do `cmp_templates`, stara tabela `cmp_template_vendors` (nigdy nie uzywana) jest usuwana.

---

## Ciasteczka (Cookies)

### `eupubconsent-v2`
- **Typ:** TC String (IAB TCF 2.2)
- **Waznosc:** 180 dni (konfigurowalne)
- **Domena:** Domena glowna (np. `.example.com`)
- **Secure:** Auto-detect (HTTPS = true, HTTP = false)
- **SameSite:** Lax

### `custom-vendors-consent`
- **Typ:** Kompaktowy string
- **Format:** `g:<google_ids>|o:<other_ids>|t:<timestamp>`
- **Opis:** ID vendorow z udzielona zgoda, oddzielone kropka. Sekcje oddzielone znakiem `|`.
- **Waznosc:** 180 dni (konfigurowalne)
- **Domena:** Domena glowna (np. `.example.com`)
- **Secure:** Auto-detect (HTTPS = true, HTTP = false)
- **SameSite:** Lax

**Przyklady wartosci:**

Wszystkie zgody udzielone (Google: 43, 46, 55, 61; Other: 1, 2):
```
g:43.46.55.61|o:1.2|t:1769720229228
```

Czesciowe zgody (Google: tylko 43 i 55; Other: brak):
```
g:43.55|o:|t:1769720229228
```

Brak zgod (reject all):
```
g:|o:|t:1769720229228
```

**Segmenty:**
| Prefix | Opis |
|--------|------|
| `g:` | ID Partnerow Google z consent=true (oddzielone `.`) |
| `o:` | ID Innych Partnerow z consent=true (oddzielone `.`) |
| `t:` | Timestamp ostatniej aktualizacji (Unix ms) |

### `cmp-reconsent-id`
- **Typ:** Liczba (timestamp)
- **Opis:** ID aktualnego reconsentu. Porownywany z `reconsentID` z konfiguracji — jesli mniejszy, wymuszany jest reconsent.
- **Waznosc:** 180 dni (konfigurowalne)

### Logika zapisu

1. **Layer 1 "Akceptuje"** - wszystkie zgody = true, ciasteczka: `eupubconsent-v2` + `custom-vendors-consent` + `cmp-reconsent-id`
2. **Layer 1 "Nie akceptuje"** - wszystkie zgody = false (tylko purpose 1 = true dla podstawowego)
3. **Layer 2/3 "Potwierdzam"** - zapisuje wybrane checkboxy
4. **Kolejnosc zapisu:** Ciasteczko `custom-vendors-consent` jest zapisywane PRZED generowaniem TC String, aby blad w TC String nie blokowal zapisu zgod custom vendorow

---

## Import danych IAB/Google

### Import GVL (Global Vendor List)

**URL:** `https://vendor-list.consensu.org/v3/vendor-list.json`

Importuje:
- Vendors (600+ partnerow IAB)
- Purposes (11 celow)
- Special Purposes (2)
- Features (3)
- Special Features (2)
- Stacks (kombinacje celow)
- Data Categories

### Import tlumaczeń celow

**URL:** `https://vendor-list.consensu.org/v3/purposes-{lang}.json`

Obslugiwane jezyki: en, pl, de, fr, es, it, nl, etc.

### Import Google ATP (Additional Consent)

**URL:** `https://storage.googleapis.com/tcfac/additional-consent-providers.csv`

Format CSV:
```
provider_id,provider_name
1234,Example Provider
```

---

## Budowanie SDK

SDK jest budowany z projektu `cmp/app` za pomoca Vite.

### Wymagania
- Node.js 18+
- npm lub yarn

### Kroki

```bash
# 1. Przejdz do katalogu app
cd ../cmp/app

# 2. Zainstaluj zaleznosci
npm install

# 3. Zbuduj SDK
npm run build

# 4. Skopiuj do wtyczki z podmiana URL-i
sed 's|http://localhost:3001/api|{API_BASE_URL}|g; s|http://localhost:3001/vendor-list|{GVL_URL}|g' \
  dist/src/index.js > ../../fast-open-cmp/assets/js/cmp-sdk.js
```

### Wazne pliki zrodowe SDK

| Plik | Opis |
|------|------|
| `src/index.ts` | Entry point, inicjalizacja |
| `src/components/ConsentManager.ts` | Glowna klasa UI, updateState() |
| `src/services/consent.ts` | Zarzadzanie stanem zgod |
| `src/services/cookie.ts` | Zapis/odczyt ciasteczek |
| `src/services/tcString.ts` | Generowanie TC String |
| `src/services/cmpApi.ts` | Implementacja __tcfapi |
| `src/components/layers/FirstLayer.ts` | Banner (warstwa 1) |
| `src/components/layers/SecondLayer.ts` | Cele (warstwa 2) |
| `src/components/layers/ThirdLayer.ts` | Partnerzy (warstwa 3) |

---

## Rozwiazywanie problemow

### Consent box nie pojawia sie

1. Sprawdz konsole przegladarki (F12)
2. Sprawdz czy API odpowiada: `GET /wp-json/fast-open-cmp/v1/cmp-config?domain_id=1`
3. Sprawdz czy domena ma przypisany szablon (prod lub test)
4. Sprawdz ustawienia Permalinks w WordPress (musza byc wlaczone)

### Ciasteczko nie jest tworzone

1. **HTTP localhost:** Cookie `secure` flag jest auto-detect. Powinno dzialac na HTTP.
2. Sprawdz konsole - powinien byc log "Setting cookie..."
3. Sprawdz czy domena ciasteczka jest poprawna (DevTools > Application > Cookies)

### Google/Inni partnerzy nie zapisuja sie

1. Upewnij sie, ze SDK zostal przebudowany po zmianach w `customVendors.ts` lub `consent.ts`
2. Sprawdz czy ciasteczko `custom-vendors-consent` jest tworzone
3. Sprawdz zawartosc ciasteczka — format kompaktowy: `g:43.46.55|o:1.2|t:1769720229228`
4. Puste sekcje (np. `g:|o:`) oznaczaja brak zgod dla danej grupy
5. Format ciasteczka to kompaktowy string — jesli widzisz stary format JSON, wyczysc ciasteczko i przetestuj ponownie

### 404 dla vendor-list

1. Sprawdz czy Permalinks sa wlaczone (Ustawienia > Bezposrednie odnoscniki)
2. Sprawdz czy route jest zarejestrowany: `GET /wp-json/fast-open-cmp/v1/vendor-list/vendor-list.json`
3. Wyczysc cache wtyczki

### Stacki pokazuja sie nieprawidlowo

1. Domyslnie wlaczony jest tylko stack 42
2. Aby zmienic, przejdz do Ustawienia > Fast Open CMP > "Wlaczone stacki"
3. Zostaw puste, aby wyswietlic wszystkie 11 celow osobno

### Cache nie dziala

1. Sprawdz czy WordPress Transients dzialaja
2. Uzyj przycisku "Wyczysc cache" w ustawieniach
3. Cache TTL domyslnie = 60 sekund

### AJAX "Blad" przy czyszczeniu cache

1. Sprawdz czy handler AJAX jest zarejestrowany w `class-admin.php`:
   ```php
   add_action('wp_ajax_focmp_clear_cache', array($this, 'ajax_clear_cache'));
   ```
2. Sprawdz nonce w JavaScript (focmpAdmin.nonce)

---

## Wskazowki dla Claude Code

### Czeste zadania

**Dodanie nowego endpointu API:**
1. Edytuj `includes/class-api.php`
2. Dodaj route w `register_routes()`
3. Dodaj metode callback

**Zmiana UI consent box:**
1. Edytuj odpowiedni plik w `cmp/app/src/components/layers/`
2. Przebuduj SDK: `cd ../cmp/app && npm run build`
3. Skopiuj z podmiana URL-i do `assets/js/cmp-sdk.js`

**Dodanie nowej tabeli:**
1. Edytuj `includes/class-activator.php`, metoda `create_tables()`
2. Podbij `FOCMP_VERSION` w `fast-open-cmp.php` — migracja odpali sie automatycznie na `admin_init`

**Dodanie nowej strony admina:**
1. Dodaj submenu w `includes/class-admin.php`, metoda `add_menu()`
2. Dodaj plik widoku w `admin/views/`
3. Dodaj metode renderujaca
4. Dodaj handlery w `handle_actions()` (POST/GET)

**Praca z listami vendorow:**
1. Listy: `class-admin.php` — metody `render_vendor_lists()`, `save_vendor_list()`, `clone_vendor_list()`, `delete_vendor_list()`
2. Filtrowanie API: `class-api.php` — metoda `is_vendor_enabled_in_list($vendor_list_id, $vendor_id, $vendor_type)`
3. Formularz: `admin/views/vendor-lists.php` — checkboxy bez `name`, JS zbiera disabled IDs do hidden fields
4. Nowa lista: `populate_vendor_list_entries()` wypelnia wszystkimi globalnie wlaczonymi vendorami

### Zaleznosci

- PHP 8.0+
- WordPress 6.0+
- MySQL 5.7+ / MariaDB 10.3+
- Node.js 18+ (tylko do budowania SDK)
- Biblioteka `@iabtcf/core` (w SDK)

### Testowanie

```bash
# Lokalne srodowisko (Docker)
cd /path/to/wordpress
docker-compose up -d

# Test API
curl "http://localhost:8089/wp-json/fast-open-cmp/v1/cmp-config?domain_id=1"

# Test vendor-list
curl "http://localhost:8089/wp-json/fast-open-cmp/v1/vendor-list?lang=pl"
```

---

## Licencja

GPL v2 or later

## Autor

Lucas Web Art - https://lucaswebart.pl
