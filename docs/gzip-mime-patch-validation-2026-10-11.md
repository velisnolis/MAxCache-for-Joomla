# Patch gzip MIME i write_gzip de l'admin

2026-10-11. Estat: `DONE_WITH_CONCERNS`.
El candidat final passa 121/121 proves al Docker en dues execucions i 34/34
a la canary reversible d'un site de client (site A). L'accés públic des del Mac continua sense validar-se.
No s'ha instal·lat el candidat a cap client ni s'ha fet release.

Els sites de client s'anomenen A, B i C. Els noms reals i els camins de les còpies
als servidors es conserven a l'arxiu intern, fora del repositori.

## Canvi i regressions

- `SnippetBuilder` genera `AddType text/html .html_gzip` i
  `AddEncoding gzip .html_gzip`, dins d'un `IfModule mod_mime.c`, només
  en mode `mod_maxcache` amb gzip activat. Les directives MIME no s'afegeixen
  en mode Apache ni quan `write_gzip=0`.
- Els dos call sites system de l'admin, aplicar i calcular l'estat actual,
  passen `write_gzip` al builder. Preview de camps i console ja el passaven.
- La fixture comprimeix amb el sufix real `_gzip`, no `.gz`.
- Tres regressions MIME fallen abans del canvi. Quatre regressions runtime
  fallen abans de propagar el paràmetre. La validació també ha detectat un fals
  `outdated`: l'ordre de cookies canviava segons el client Joomla i el console
  hi afegia la seva sessió interna, que no és una cookie HTTP.
- El builder ordena el conjunt de cookies, sense canviar-ne els membres.
  `factorySessionCookieNames()` no obre ni afegeix la sessió `RuntimeStorage`
  del client CLI. Sis regressions d'ordre i dues de sessió CLI fallen abans
  d'aquestes correccions. Passen 111 assertions helper + 192 runtime = 303,
  route-report, lint PHP i `git diff --check`.
- El runner executa ara `php cli/joomla.php maxcache:status --format=json`
  com a procés real: no usa només la classe de diagnòstic dins del bootstrap web.
  Verifica coherència admin/CLI amb gzip on/off, estat obsolet quan es canvia
  el paràmetre i recuperació en reaplicar. El writer real i Apache validen
  els bytes gzip, el tipus HTML i el fallback pla.

| Troballa | Classe | Impacte | Esforç | Risc |
|---|---|---|---|---|
| Gzip sense declaració MIME/encoding al snippet | `bug` | alt | baix | mitjà |
| Admin no propagava `write_gzip` en aplicar/calcular l'estat | `bug` | mitjà | baix | baix |
| Hash diferent per ordre de cookies o sessió CLI no HTTP | `bug` | mitjà | baix | baix |

SHA-256 dels fitxers del patch:

- `Extension/Maxcache.php`:
  `73d2804c6f355155b19934e9631c4ce0b4c08080d1724cab3a4eedcb7c783d0d`.
- `Support/SnippetBuilder.php`:
  `7a2db30049aa7fa9e251d88d2750023c45f655203d4ad886a3bc2bb3ed99f6d3`.
- `Support/BypassCookieNames.php`:
  `31903cccf75e8c914151e636245444e02e8117520a996c40b81a73878b328f32`.

## Site A: 34/34

Execució final `2026-10-11T12:14:01+00:00`, HTTPS directe a l'origen amb SNI i
certificat validat. Els cinc helpers usats tenen els mateixos SHA-256 que el checkout.
No s'han afegit directives MIME manualment al runner: arriben del snippet candidat.

El gzip seleccionat arriba amb `Content-Encoding: gzip`, `Content-Type: text/html`
i el client descomprimeix el sentinel esperat. Sense `Accept-Encoding`, la
resposta és el sentinel pla i no hereta encoding gzip. Passen queries, cookies,
POST, exclusions integrades/pròpies i integritat dels artifacts sintètics.

Còpia immediata, extreta i verificada per tots els hashes, fora del web:
`~/codex-backups/maxcache-gzip-final-20261011/` del compte del site, propietari el mateix compte, 0700.
Tar SHA-256:
`3a4af095d3e07c33b655a7436da6a69785e1b52a8f52abf983164375742a16f7`.
Evidència sanititzada: `evidence.json` dins d'aquest directori privat i
[còpia de QA](qa/gzip-mime-2026-10-11/site-a-evidence-final.json).

La canary `/maxcache-canary-20261011-gzip-final-26fe5c` s'ha mogut a `retired-canary`
fora del document root. Absència del directori i hashes originals verificats.
Plugin instal·lat, params i `.htaccess` principal intactes. Portada i admin
a l'origen retornen 200 amb TLS validat. No s'ha purgat cache real ni reiniciat Apache.

El hostname públic també havia retornat 200 des del servidor. Des del Mac, dos
probes anteriors han arribat al timeout de 15 segons, un forçant IPv4; la represa
també arriba al timeout de connexió de 5 segons, HTTP 000. No s'ha
determinat la causa d'aquesta diferència d'accés ni s'ha canviat xarxa/WAF;
no presentar-la com una comprovació pública correcta des del Mac.

La prova només valida el lector amb HTML sintètic. No valida una instal·lació
del paquet candidat al site A ni substitueix les proves de Joomla al laboratori.

## Docker: 121/121 en dues execucions

Laboratori i contenidors dedicats
inicialment aturats; web a `127.0.0.1:8083`, DB i volums independents.
Site `MAxCache Joomla 6 isolated lab`, Joomla 6.1.4, PHP 8.4.26,
Apache amb `mime_module` i `rewrite_module`, sense el binari `mod_maxcache`.
Instal·lació del candidat i execució del runner com a `www-data`.

Còpia immediata al host Docker, fora del web:
`backup/pre-gzip-mime-20261011T1208Z/` dins del directori del laboratori.
Tar llegible i fitxers principals extrets amb hashes coincidents; dump de 76 taules.

- Tar: `847653a37eb8ffdad5c5aeecd0ce81504c04477b9f982cbb8b01dc920bf789df`.
- SQL: `609006fb687e8f3a2ec374f7a99bacb6c5d1dfb6cf0768069f332d8da708b474`.
- Còpies addicionals del system plugin i `.htaccess` abans de cada correcció
  de cookies: `pre-cookie-order-system.tar.gz` i `pre-console-cookie-guard-system.tar.gz`.

Paquet final de laboratori, versió conservada 0.1.39:
`.docker-build/joomla6-gzip-final-20261011-vp5mvI/pkg_maxcache-gzip-final-candidate.zip`.
SHA-256: `891851458aa860cc7fb52458a41738d4c10e81a87825891a4e25b9b874dc4071`.
ZIP verificat; no s'ha executat el build que reescriu el feed.

[Primera passada final](qa/gzip-mime-2026-10-11/evidence-final-first.json),
`2026-10-11T12:13:50+00:00`, i
[segona passada](qa/gzip-mime-2026-10-11/evidence-final.json),
`2026-10-11T12:14:29+00:00`: 121 comprovacions cadascuna, cap fallada.
Els quatre fitxers instal·lats comprovats coincideixen amb el checkout.
[CLI final](qa/gzip-mime-2026-10-11/status-final.json): `snippet_state=applied`,
hash esperat/aplicat iguals, zero tokens CSRF a cache i cap issue.

El bloc `mod_maxcache` està inactiu al Docker. Per validar MIME amb Apache real,
el runner extreu el bloc MIME generat i l'aplica només al directori de cache
del lab; serveix el gzip del writer i comprova encoding, tipus i bytes.
Aquest `.htaccess` temporal es retira i es restaura el mode Apache del lab.
El lector binari amb HTTPS es valida separadament al site A.
No és una prova funcional de RSForm, YOOtheme o Admin Tools.

El laboratori torna al seu estat inicial aturat, amb volums i còpies conservats.
El Joomla antic s'ha iniciat automàticament amb el host del laboratori, però no s'ha
instal·lat ni canviat res en aquell site. No s'han modificat altres màquines.

## Diagnòstic de coherència admin/console

- Símptoma: el snippet acabat d'aplicar es donava per obsolet des d'un altre client.
- Causa: mateix conjunt de cookies en ordre diferent; la CLI real hi afegia una
  sessió interna sense ús HTTP. La comparació en lectura confirma que la resta
  de línies és idèntica. No és un defecte MIME ni una incidència de configuració del site A.
- Canvi: ordre estable al builder i no descobrir cookies HTTP des de la sessió CLI.
- Prova vermella: [118 checks, dues fallades d'ordre](qa/gzip-mime-2026-10-11/evidence-before-cookie-order.json).
  Després, [121 checks, tres fallades de CLI real](qa/gzip-mime-2026-10-11/evidence-before-console-cookie-guard.json).
  No s'han relaxat les assertions; s'ha reforçat el punt de prova del console.
- Regressions: `tests/run.php` i `tests/integration/joomla6.php`; estat del defecte `DONE`.
  Les exclusions de sessió continuen passant al writer, Apache i la canary real.
- Antecedent: els commits `8e76dca` i `d6aa043` introduïen la descoberta de cookies;
  no es refactoritza cap altra part de sessions ni del console.

En un futur desplegament, l'ordre estable canviarà el hash dels snippets antics.
Caldrà revisar i reaplicar el bloc explícitament; aquestes proves no han reaplicat
el `.htaccess` principal ni han desplegat el plugin a cap client.

Hi ha còpia local prèvia verificada a l'arxiu intern, també de les correccions
de coherència i de la documentació.
Feed sense canvis, SHA-256
`c176eb34fc7294d71fb8feb06021b0da7ba753bc2e18bfb6be671ecf4a890988`.
Sense commit, push, purga de client o release.
