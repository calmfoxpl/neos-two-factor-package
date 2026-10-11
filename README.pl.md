# Neos Two-Factor Package

[![Build](https://github.com/calmfoxpl/neos-two-factor-package/actions/workflows/build.yml/badge.svg)](https://github.com/calmfoxpl/neos-two-factor-package/actions/workflows/build.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

[English](README.md) | **Polski**

Weryfikacja dwuetapowa dla panelu Neos CMS. Każdy użytkownik wybiera swój drugi składnik: **klucz dostępu** (passkey: odcisk palca, twarz albo PIN urządzenia) albo **aplikację uwierzytelniającą** (TOTP).

## Możliwości

- **Wybór przy konfiguracji:** najpierw proponujemy klucz dostępu, bo nie da się go wyłudzić i niczego się nie przepisuje. Potem aplikację uwierzytelniającą, z kodem QR albo kluczem do wpisania ręcznie.
- **Przy logowaniu:** po haśle klucz dostępu to jeden gest, a aplikacja to sześć cyfr. Strona wygląda jak logowanie Neosa, łącznie z logo i tłem, jeśli projekt je zmienił.
- **Polityka w panelu:** *Administracja → Weryfikacja dwuetapowa*:
  - **wymagana:** każdy użytkownik panelu musi coś sparować; do tego czasu trafia wyłącznie na ekran konfiguracji,
  - **dobrowolna:** każdy decyduje sam w swoich ustawieniach,
  - **wyłączona:** nikt nie jest pytany, a sparowane metody zostają na wypadek zmiany polityki.
- **Tam, gdzie zarządza się użytkownikami:** strona użytkownika w *Administracja → Zarządzanie użytkownikami* (szczegóły i edycja) pokazuje jego drugi składnik z tymi samymi dwiema akcjami, więc zgubiony telefon załatwia się tam, gdzie jest użytkownik. Tylko dla administratorów.
- **Przegląd dla administratora:** moduł polityki pokazuje każde konto panelu i to, co ma sparowane, z dwiema akcjami:
  - **Resetuj:** usuwa wszystkie sparowane metody, wylogowuje użytkownika ze wszystkich sesji i wymaga nowego parowania przy następnym logowaniu, niezależnie od polityki. Na wypadek zgubionego telefonu.
  - **Wyłącz:** usuwa wszystkie sparowane metody, a dalej decyduje polityka.
- **Samoobsługa dla każdego:** *Użytkownik → Weryfikacja dwuetapowa* pokazuje klucze dostępu (z datą ostatniego użycia) i aplikację. Każdy może dodać klucz na każdym swoim urządzeniu, sparować albo usunąć aplikację. Przy polityce wymaganej nie da się usunąć ostatniej metody; wystarczy najpierw dodać nową, a potem usunąć starą.
- **Wiersz poleceń:** `./flow twofactor:reset <login> [--disable]`, `./flow twofactor:policy [--policy required|optional|disabled]` i `./flow twofactor:list`, na wypadek gdy nikt nie może wejść do panelu.
- **Zgadywanie się nie opłaca:** po pięciu błędnych kodach (liczba do ustawienia) sesja się kończy i trzeba ponownie wpisać hasło.
- **Ostrożnie z sekretami:** sekret aplikacji jest szyfrowany biblioteką libsodium kluczem wyprowadzonym z klucza szyfrowania Flow tej instalacji i trafia na konto dopiero po wpisaniu kodu, który z niego powstał. Klucz dostępu zapisujemy dopiero po weryfikacji podpisu i wyłącznie jako klucz publiczny.
- **Ostrożnie z kluczami dostępu:**
  - weryfikacja użytkownika jest wymagana,
  - wyzwania są jednorazowe i przypisane do sesji,
  - pochodzenie (origin) sprawdzamy dokładnie, a nie po końcówce adresu,
  - akceptujemy wyłącznie klucze danego konta,
  - zapamiętujemy licznik podpisów, więc powtórzona odpowiedź zostanie zauważona.

  Oparte na [lbuchs/webauthn](https://github.com/lbuchs/WebAuthn), bibliotece bez własnych zależności.
- **Podszywanie się działa dalej:** gdy administrator działa jako inny użytkownik, liczy się drugi składnik administratora.
- **Tłumaczenia:** angielski i polski. Strony mówią w języku, który użytkownik wybrał dla panelu.

## Wymagania

| | Wersja |
|---|---|
| Neos | 8.3, 8.4, 9.x |
| PHP | 8.2, 8.3, 8.4, z rozszerzeniami `sodium` i `openssl` |
| Baza danych | MySQL / MariaDB albo PostgreSQL |
| Przeglądarka (klucze dostępu) | dowolna aktualna, po https (zwykłe http działa tylko na `localhost`) |

Sprawdzone na Neos 8.3.35 (Flow 8.3.20, PHP 8.2), Neos 8.4.8 (Flow 8.4.5, PHP 8.2) i Neos 9.1.10 (Flow 9.1.3, PHP 8.3), za każdym razem z MariaDB.

Ten pakiet zastępuje inne pakiety drugiego składnika dla panelu, np. `sandstorm/neostwofactorauthentication`. Dwa naraz poprosiłyby użytkowników o dwa drugie składniki; wybierz jeden.

## Instalacja

```bash
composer require calmfox/neos-two-factor-package

./flow doctrine:migrate
./flow flow:cache:flush
```

Migracja dodaje dwie tabele: `calmfox_twofactor`, po jednym wierszu na konto panelu, które coś sparowało, oraz `calmfox_twofactor_policy`, z polityką wybraną w panelu. Usunięcie użytkownika w Neosie usuwa jego wiersz.

Niczego nie trzeba dopisywać do `Routes.yaml` projektu: pakiet sam obsługuje swoje adresy (`/neos/two-factor/...`) zaraz za routerem Flow, więc działają niezależnie od tego, czy projekt ma własny `Configuration/Routes.yaml`.

**Zanim wdrożysz na działającą stronę**, zdecyduj o polityce. Domyślna jest **wymagana**, więc przy następnym logowaniu każdy użytkownik, łącznie z Tobą, trafi na ekran konfiguracji. To działa, ale może zaskoczyć. Żeby wprowadzić to stopniowo, zacznij od:

```bash
./flow twofactor:policy --policy optional
```

Użytkownicy parują wtedy w *Użytkownik → Weryfikacja dwuetapowa*, `./flow twofactor:list` pokazuje, kto już to zrobił, a gdy wszyscy są gotowi, `./flow twofactor:policy --policy required` wprowadza obowiązek dla wszystkich.

## Konfiguracja

O tym, kto musi używać drugiego składnika, decyduje się w panelu. Reszta jest w `Settings.yaml`:

```yaml
Calmfox:
  TwoFactor:
    # dopóki nikt nie wybierze w panelu albo przez ./flow twofactor:policy
    defaultPolicy: required          # required | optional | disabled
    # dostawcy, których użytkownicy są pytani; Neos loguje redaktorów przez Neos.Neos:Backend
    authenticationProviders:
      - 'Neos.Neos:Backend'
    totp:
      issuer: ''                     # nazwa w aplikacji; puste: host strony
      window: 1                      # ile 30-sekundowych kroków w każdą stronę akceptujemy
    passkeys:
      enabled: true
      name: ''                       # jak urządzenie nazywa tę stronę; puste: host
      domain: ''                     # identyfikator strony (RP ID), np. example.com; puste: host żądania
    maximumAttempts: 5               # błędne kody, po których sesja się kończy
```

- **`passkeys.domain`:** ustaw, zanim ktokolwiek sparuje klucz dostępu, jeśli panel jest dostępny pod więcej niż jedną nazwą hosta. **Późniejsza zmiana sprawi, że wszystkie sparowane klucze przestaną działać.**
- **Inny dostawca uwierzytelniania** (np. logowanie jednokrotne) można dopisać do `authenticationProviders`. Jego użytkownicy potrzebują też wzorca żądań pakietu na tym dostawcy, tak jak pakiet dodaje go do `Neos.Neos:Backend`: patrz `Neos.Flow.security.authentication.providers` w [Configuration/Settings.yaml](Configuration/Settings.yaml).

## Jak to działa

**Strażnik** to middleware tuż za punktem wejścia bezpieczeństwa Flow, a nie zaczep na samym logowaniu, bo pytanie nie brzmi „czy właśnie się zalogowałeś", tylko „czy to żądanie może pójść dalej". Sesja, która nie odpowiedziała na wyzwanie, jest zatrzymywana przy każdym żądaniu, także otwartym z zakładki kilka godzin później, i także na frontendzie, gdzie sesja panelu pokazałaby nieopublikowane treści. Własne strony pakietu oraz logowanie i wylogowanie Neosa są zawsze dostępne, więc nikt, kto nie może odpowiedzieć, nie zostaje uwięziony. Żądanie ze skryptu (edytor treści pobierający JSON) dostaje zwykłe `403` zamiast przekierowania, a najbliższa wczytana strona prowadzi tam, gdzie trzeba.

**To, co zostało potwierdzone,** przechowujemy w sesji Flow razem z informacją, które konto potwierdziło, więc nie przejmie tego ktoś, kto zaloguje się następny w tej samej przeglądarce. Świeże hasło to czyści, wylogowanie niszczy sesję, a po potwierdzeniu sesja dostaje nowy identyfikator.

**Kody jednorazowe** to RFC 6238 na RFC 4226 z parametrami, których używa każda aplikacja: SHA-1, krok 30 sekund, sześć cyfr. Są zaimplementowane w pakiecie, a nie dociągane jako zależność: to czterdzieści linii arytmetyki, a wektory testowe z RFC są w testach.

**Kod działa raz.** Kod jest poprawny przez cały swój 30-sekundowy krok, a dla spóźnionych zegarów akceptujemy też krok obok, więc te same sześć cyfr odpowiedziałoby dwa razy: odczytane z udostępnionego ekranu, podejrzane zza ramienia albo powtórzone z przechwyconego żądania. Zapisujemy najnowszy krok, którego konto użyło, i odrzucamy wszystko z tego kroku lub wcześniejszego. Kod wpisany przy parowaniu też liczy się jako zużyty. Krok zajmujemy jednym warunkowym `UPDATE`, a nie odczytem i zapisem, bo dwa równoczesne żądania z tym samym kodem przeszłyby przez to drugie.

**Klucze dostępu** to jedyny drugi składnik, którego phishing nie przekaże dalej: podpis obejmuje adres strony, więc podróbka nigdy nie dostanie ważnego. Biblioteka akceptuje każdy host, który tylko kończy się skonfigurowaną domeną, dlatego pakiet sam sprawdza pochodzenie: dokładnie ta strona albo prawdziwa subdomena, po https, a zwykłe http tylko na `localhost`, tak jak w przeglądarkach.

**Konfiguracja nie zostawia niczego w połowie.** Sekret aplikacji czeka w sesji i trafia na konto dopiero po wpisaniu kodu, który z niego powstał; klucz dostępu zapisujemy dopiero po weryfikacji podpisu.

**Sekret w bazie.** Flow nie ma szyfrowania wartości, które trzeba potem odczytać, a sekret TOTP trzeba. Szyfrujemy go więc (libsodium secretbox) kluczem wyprowadzonym (HKDF) z klucza szyfrowania Flow tej instalacji, żeby zrzut tabeli nie wystarczył do generowania kodów. Druga strona medalu: nowy klucz szyfrowania Flow sprawia, że zapisanych sekretów nie da się odczytać. Tacy użytkownicy są wtedy traktowani jak bez aplikacji i proszeni o ponowne sparowanie; klucze dostępu to nie dotyczy.

## Odzyskiwanie konta

Jeśli użytkownik zgubi telefon, administrator resetuje go na stronie użytkownika w *Administracja → Zarządzanie użytkownikami* albo w przeglądzie w *Administracja → Weryfikacja dwuetapowa*. Jeśli nikt nie może się zalogować:

```bash
./flow twofactor:reset anna
```

Hasło zostaje, a nowe urządzenie paruje się przy następnym logowaniu. `--disable` usuwa sparowane metody bez wymogu nowego parowania, a dalej decyduje polityka. `--provider` wskazuje konto innego dostawcy uwierzytelniania.

## Wygląd

- **Strony:** ekrany weryfikacji i konfiguracji korzystają z arkuszy stylów, logo i tła logowania Neosa (`Neos.Neos.userInterface.backendLoginForm`), więc projekt, który je zmienił, zobaczy je także tutaj. Szablony są w `Resources/Private/Templates/Challenge`, `Setup` i `Module` i można je podmienić przez `Views.yaml` Flow.
- **Zarządzanie użytkownikami:** sekcja na stronie użytkownika powstaje przez podmianę wyłącznie ramki strony (układ `BackendSubModule`) modułu użytkowników Neosa na `Resources/Private/Layouts/NeosUsersModule`, nigdy szablonów Neosa, patrz [Configuration/Views.yaml](Configuration/Views.yaml). Neos używa jednej ramki na akcję, więc pakiety Calmfox dzielą jedną: każdy rejestruje swoją sekcję w `Calmfox.UserManagement.sections` (tutaj `Calmfox\TwoFactor\UserManagement\TwoFactorSection`), a ramka dowolnego z nich renderuje wszystkie, dzięki czemu [calmfox/neos-admin-invitation-package](https://github.com/calmfoxpl/neos-admin-invitation-package) i ten pakiet mogą działać razem. Projekt, który podmienia ten sam układ, renderuje sekcje przez `<cf2fa:userSections user="{user}" />`.
- **CSS:** wszystko, co dodaje pakiet, ma przedrostek `cf2fa-`.
- **Teksty:** `Resources/Private/Translations/{en,pl}/Main.xlf`.

## Czego to nie obejmuje

- **Zaufanych urządzeń.** Każde logowanie wymaga drugiego składnika. Nie ma „zapamiętaj tę przeglądarkę na 30 dni".
- **Ponownego logowania w edytorze treści.** Gdy sesja wygaśnie w edytorze treści, Neos proponuje zalogowanie w okienku, w którym nie ma miejsca na drugi składnik. Po wpisaniu tam hasła odśwież stronę: pojawi się ekran weryfikacji.
- **Logowania na frontendzie.** Pakiet chroni panel. Użytkownicy frontendu logujący się przez własnego dostawcę nie są pytani, chyba że ten dostawca zostanie dopisany do `authenticationProviders`.

## Rozwój

Testy jednostkowe obejmują rdzeń niezależny od frameworka (kody jednorazowe sprawdzane wektorami z RFC, base32, decyzje polityki, ewidencję kluczy dostępu, szyfrowanie sekretu i adresy stron) oraz okablowanie, które spotyka się dopiero w Neosie: każdy identyfikator tłumaczenia w obu językach, uprawnienie dla każdej akcji kontrolera, moduły w ustawieniach. Nie potrzebują ani Neosa, ani bazy danych:

```bash
phpunit -c phpunit.xml.dist
```

Wystarczy dowolny PHPUnit 10 lub nowszy; pakiet nie wymaga własnego `composer install`.

## Bezpieczeństwo

Jak zgłosić podatność: [SECURITY.md](SECURITY.md).

## Licencja

[MIT](LICENSE)
