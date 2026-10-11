# Prova de mod_maxcache amb HTTPS real

2026-10-11. Estat: `DONE_WITH_CONCERNS`.

Aquest és el diagnòstic anterior al patch MIME. La
[validació posterior del patch](gzip-mime-patch-validation-2026-10-11.md)
conserva els resultats nous i la validació completada al Docker.

S'ha provat el lector `mod_maxcache` en un site de client (site A), en una carpeta temporal amb
HTML sintètic. El snippet candidat sense directives MIME addicionals passa
**31/32 comprovacions**: falla gzip. Amb `AddEncoding` i `AddType` només a
la carpeta de prova, passa **32/32**. No és una validació completa del
paquet candidat instal·lat a producció.

Els sites de client s'anomenen A, B i C. Els noms reals i els camins de les còpies
als servidors es conserven a l'arxiu intern, fora del repositori.

## Abast, còpia i retirada

- Inventari en lectura de tres sites de client (A, B i C): Joomla 6.1.4,
  PHP CLI 8.4.26, MAxCache 0.1.39 i mode `mod_maxcache`.
- Servidor CloudLinux: `ea-apache24-mod_maxcache` i `libmaxcache` 1.2.5,
  mòdul carregat. Sense instal·lar paquets ni reiniciar Apache.
- El site A tenia el `.htaccess` menys complex; els sites B i C tenen
  proteccions generades per Admin Tools. Només s'ha escrit la canary al site A.
- Còpia completa del site A registrada a JetBackup el
  `2026-10-11T00:00:08+00:00`, `backup_contains=511`, no marcada com a
  malmesa ni desactivada. No s'ha fet una restauració completa de JetBackup.
- Còpia immediata dels fitxers implicats al servidor, fora del web:
  `~/codex-backups/maxcache-https-canary-20261011T1135Z/` del compte del site.
  Directori propietat del compte, permisos 0700. Inclou `.htaccess`, `configuration.php`
  i plugins system/console MAxCache; no s'ha baixat aquesta còpia al Mac.
- Tar extret en una carpeta de prova fora del web, amb tots els SHA-256
  coincidents. SHA-256 del tar:
  `3a4af095d3e07c33b655a7436da6a69785e1b52a8f52abf983164375742a16f7`.
- Les tres carpetes de prova s'han mogut fora del document root després de
  cada passada, també quan falla una assertion. Es conserven per reproduir
  el diagnòstic. No hi ha canary activa al web.
- Hashes dels fitxers originals verificats abans i després. No s'ha canviat
  el plugin instal·lat, el `.htaccess` principal ni els params. Cap purga de
  la cache real, escriptura de DB de test, login de prova, release o feed.

Les peticions anaven directament a l'IP de l'origen amb SNI del domini del site,
validació TLS activada i resultat 0. Resoldre contra 127.0.0.1 seleccionava
un altre vhost/certificat: aquesta primera comprovació no era vàlida.

## Què acredita la prova

Amb el mòdul desactivat, el client rep el fitxer de fallback. Activant-lo,
rep un sentinel diferent del fitxer `index-https.html`, no l'HTTP. Això evita
donar per bona una exclusió només perquè el lector no estigués funcionant.

Passen vuit variants de query, vuit cookies configurades/derivades de Joomla,
una cookie aliena com a control positiu, POST, exclusions integrades de
users/reset/remind, administrator, api i ajax, i una exclusió pròpia ancorada.
Els valors de cookie són sintètics, no sessions autenticades. Els artifacts
de prova mantenen els seus hashes.

La prova no arrenca Joomla a les rutes sintètiques. ACL, respostes privades,
CSRF i el writer del candidat tenen la validació separada del
[laboratori Joomla 6](joomla6-validation-2026-10-11.md), no una instal·lació
del candidat al site A. Tampoc acredita compatibilitat funcional amb RSForm,
YOOtheme o Admin Tools.

## Troballes pendents: gzip

1. **Problemes detectats.** `bug` d'integració del snippet gzip: amb
   `{GZIP_SUFFIX}` i el fitxer correcte, el servidor retorna bytes gzip sense
   `Content-Encoding`. Impacte alt si s'activa aquest snippet; esforç baix,
   risc mitjà. `bug` separat: els dos call sites del plugin system no passen
   `write_gzip` al builder; el diagnòstic console sí. Impacte mitjà, esforç
   baix, risc baix. Les tres instal·lacions revisades tenen `write_gzip=0`.
2. **Causa.** La primera fixture, feta per aquesta prova, usava `.gz`:
   era un error del test, no del writer. El writer ja genera `_gzip`.
   Corregint la fixture, es confirma la selecció del fitxer però no l'encoding
   HTTP en aquest servidor 1.2.5. No s'atribueix encara a un defecte intern
   del binari: el problema reproduït és el contracte d'integració actual.
3. **Canvi recomanat.** Un patch local separat que propagui `write_gzip` als
   dos call sites system i declari encoding/tipus HTML al snippet gzip
   `mod_maxcache`. No s'ha implementat en aquesta passada de validació.
4. **Alternativa mínima.** Mantenir `write_gzip=0`; no activar un snippet
   `{GZIP_SUFFIX}` sense validar capçaleres. No cal canviar aquests sites.
5. **Proves.** Generar el gzip amb el writer real; comprovar selecció, MIME,
   `Content-Encoding`, descompressió i fallback sense `Accept-Encoding`.
   Mantenir bypass de query/cookie i retirada de plain/gzip per respostes
   privades. Assegurar que admin i console esperen el mateix hash de snippet.
6. **Aquesta setmana.** Preparar aquest patch petit i provar-lo al laboratori
   abans de considerar desplegament; no instal·lar encara el candidat als clients.

La [documentació de CloudLinux](https://docs.cloudlinux.com/cloudlinuxos/shared-pro/)
defineix `{GZIP_SUFFIX}` com `_gzip` i indica capçaleres gzip automàtiques.
La prova d'aquest servidor no confirma aquesta última afirmació sense
directives MIME addicionals. Amb les directives limitades a la canary,
`Content-Encoding: gzip` arriba i el client descomprimeix el sentinel correcte.

## Evidència i estat final

Al directori privat de la còpia del servidor:

- `evidence.json`: primera fixture `.gz`, 28/29; error de la prova conservat.
- `evidence-corrected.json`: fixture `_gzip`, 31/32; falta d'encoding real.
- `evidence-with-encoding.json`: 32/32, amb directives MIME addicionals
  només a la canary; execució `2026-10-11T11:31:42+00:00`.

Després de retirar-les: portada pública del site A i portada/admin de l'origen
HTTP 200 amb TLS validat; fitxers originals i absència de les canarys verificats.
Checks locals: 93 assertions helper + 184 runtime, route-report i
`git diff --check`, tots passen. No s'ha canviat codi funcional en aquesta passada.

Site C: `/ca/`, `/es/` i `/en/` tornen a donar 404 en la comprovació d'avui.
És el comportament esperat: el site té un sol idioma i aquestes rutes no existeixen.
No és cap incidència ni cal incloure-les a les rutes de route-report d'aquest site.
No s'ha purgat, reparat ni restaurat cache antiga en aquest site.
