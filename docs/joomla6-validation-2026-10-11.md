# Validació i correccions amb Joomla 6 real

2026-10-11. Resultat final: **101/101 comprovacions d'integració passen en dues passades**.
Els tres patches inicials passen, i s'han corregit tres defectes addicionals:
cookies de sessió, exclusions Apache i patrons d'URL ancorats al writer.
Estat: `DONE_WITH_CONCERNS`, pels límits d'entorn indicats al final.
És una validació del laboratori, no una autorització de desplegament o release.

El resultat inicial de 82/83 es conserva com a antecedent: va detectar
la cookie que el snippet generat des de l'admin no excloïa.

## Entorn i abast

- Host Docker de laboratori dedicat, a la xarxa interna.
- Joomla 6.1.4, PHP 8.4.26, Apache 2.4.68, MySQL 8.4.
- Contenidors nous `maxcache-j6-20261011-web` i `maxcache-j6-20261011-db`,
  xarxa i volums propis. Web només a `127.0.0.1:8083` del host Docker.
- El Joomla anterior de port 8080 continua sent 5.4.5: no s'hi ha instal·lat
  cap paquet ni canviat configuració. Cap altre site del laboratori s'ha usat.
- MAxCache candidat instal·lat pel CLI de Joomla com a `www-data`, versió
  conservada `0.1.39`. Sense commit, push, release, feed ni producció.
- Les quatre classes afectades instal·lades tenen els mateixos SHA-256 que
  el checkout; l'evidència final els inclou. El paquet final té SHA-256
  `da8fc09cf910f83dae704e510927b6c381627d5979f869540f9d9aaf71d882cd`.
  El primer candidat, només amb els tres patches inicials, tenia SHA-256
  `67e8050252340e2e8531710eeca58952bb38d5ce99c10890eebca5aec459ff7f`.

Imatge [Docker oficial de Joomla](https://github.com/joomla-docker/docker-joomla),
fixada al digest indicat al README de les proves.

## Proves dels patches

| Patch | Evidència amb Joomla i HTTP reals |
| --- | --- |
| Permisos | Guest i gestor de plugins reben 403 a les quatre accions, sense canviar cache, `.htaccess` ni params. El gestor de cache no pot aplicar regles ni configurar Regular Labs. Pot purgar amb CSRF vàlid; sense token no té efectes. Un Super User pot aplicar el snippet. |
| Respostes privades | `private`, `no-store`, token Joomla, missatge tardà, Location, MIME JSON i PSR 500 no escriuen cache i eliminen plain/gzip sintètics vells. El client rep `no-store`. Els tokens difereixen entre dues visites guest noves. També s'elimina gzip vell amb `write_gzip=0`. Un HTML públic sí que s'escriu i coincideix amb la resposta HTTP i el gzip. |
| Queries | Els params abans allowlisted, arrays, params desconeguts i combinacions no modifiquen hashes plain/gzip ni creen una entrada canònica nova. La regla Apache serveix un sentinel estàtic sense query, però amb query arriba a Joomla i conserva el fitxer. |

Les comprovacions de permisos fan login real a l'administrador amb usuaris
de DB, sessions i tokens Joomla. El plugin sintètic només produeix cossos i
polítiques de resposta. No injecta identitats ni substitueix l'ACL.

Algunes queries de paginació (`limitstart`, `start`, arrays) obtenen **404 del
router d'article**, no 200. L'evidència conserva aquests status: la prova
acredita el bypass i la no-col·lisió, no que aquestes rutes siguin vives.
Joomla reemplaça `private, no-store` amb el seu `no-store, no-cache` en enviar
capçaleres repetides. El bloqueig al client es manté; no cal canviar el plugin
per conservar una etiqueta `private` si `no-store` ja arriba per HTTP.

## Correccions addicionals implementades

| Troballa | Tipus | Impacte | Esforç | Risc | Canvi i prova |
| --- | --- | --- | --- | --- | --- |
| Cookie del frontend absent quan l'admin genera el snippet | `bug` | alt | baix | mitjà | Doble hash Joomla, noms site/admin/custom i sessió actual sense construir serveis d'altres clients. Una cookie guest real ara arriba a Joomla i no rep el sentinel estàtic. |
| Exclusions d'URL absents al snippet Apache | `bug` | alt | baix | mitjà | `RewriteCond` amb exclusions integrades i pròpies. Artifacts vells de reset/remind no es serveixen; HTTP 200 viu, tokens diferents i cap HTML/gzip nou. |
| Patrons amb `^`/`$` avaluats contra dues URLs concatenades | `bug` | alt | baix | baix | Comparar URL pública, path i URL interna separadament; conservar el subjecte antic com a fallback. Vuit regressions locals i bypass real d'una exclusió acabada en `$`. |

Símptoma inicial de cookies: aplicar el snippet des de l'admin deixava fora la cookie efectiva
del frontend. Una petició guest amb cookie de sessió a una ruta amb un
artifact estàtic retornava el sentinel, HTTP 200, sense executar el writer.
No és una resposta viva de Joomla. El bypass del writer sí que funciona si
la petició arriba a PHP.

Causa confirmada al codi de Joomla 6.1.4: el provider genera un nom amb
`md5(secret + session_name)` i `Joomla\CMS\Session\Session` torna a aplicar
`md5` al constructor. El nom habitual del frontend és així
`md5(md5(secret + 'site'))`. `BypassCookieNames::joomlaSessionCookieNames()`
feia només un hash i usava la llavor de classe com a fallback. La detecció via
objectes de sessió des de l'admin no recuperava la cookie efectiva del site.
S'ha comparat el nom rebut per HTTP amb el servei real de sessió del site:
coincideixen, i aquest nom no apareix al `.htaccess` aplicat.

El helper abans de corregir-lo era idèntic al de `HEAD`/0.1.39 (SHA-256
`2db39a849a85186065a316040b8bd4a483d60a4ba76624f4f84249708b3cd4d4`);
no és una regressió introduïda pels tres patches.

Canvis: [BypassCookieNames.php](../pkg_maxcache/packages/plg_system_maxcache/src/Support/BypassCookieNames.php),
[SnippetBuilder.php](../pkg_maxcache/packages/plg_system_maxcache/src/Support/SnippetBuilder.php)
i `Maxcache::isExcludedUrl()` a
[Maxcache.php](../pkg_maxcache/packages/plg_system_maxcache/src/Extension/Maxcache.php).
Les proves fallen amb el codi anterior i passen amb el candidat final.
S'ha contrastat el mateix doble hash al codi del Joomla 5.4.5 existent,
sense instal·lar-hi res. Els noms custom/shared es cobreixen en tests del
helper; no s'ha canviat la configuració de sessions d'un site existent.

Alternativa mínima sense patch: incloure explícitament el nom real de la cookie del
frontend a `bypass_cookies` i tornar a aplicar les regles en staging.
És una alternativa proposada, no un canvi fet a cap client.
Una purga i regeneració de regles de producció necessitaran una aprovació
separada; instal·lar el paquet no repara artifacts ni regles antics tot sol.

## Serialització Apache i estabilitat del runner

Els patrons amb espais es delimiten amb cometes, però no es dupliquen les
barres de regex. El [parser d'Apache 2.4.68](https://github.com/apache/httpd/blob/2.4.68/modules/mappers/mod_rewrite.c#L2720)
no escapa la seva pròpia cometa delimitadora. Per això es tria una cometa
absent del patró. Si hi apareixen tant `"` com `'`, el snippet desactiva la
lectura estàtica i explica que cal escriure-les com `\x22`/`\x27`.
No s'omet l'exclusió ni es genera un `.htaccess` que trenqui el site.
Trade-off: aquell patró deixa Joomla treballant sense acceleració estàtica
fins que s'expressin les cometes de manera segura i es reapliquin les regles.
Això es comprova per HTTP, inclosa la recuperació sense reiniciar Apache.

Una primera serialització amb cometes escapades va produir 500 només al
laboratori; es va restaurar exactament el `.htaccess` de la còpia prèvia
abans d'instal·lar el candidat corregit. Aquesta variant no és el paquet final.
El runner ara comença purgant només la seva cache, després dels guards de
site/versió: una prova de lectura desactivada pot deixar HTML públic escrit,
i això no ha de contaminar la següent execució.

## Còpies, evidència i represa

La còpia inicial és al host Docker, al directori `backup/` del laboratori,
fora del document root.

- `site-before-candidate.tar.gz`: SHA-256
  `b29d7e8f4ece259d6c9287a4ba26d8755137d5c6cda978eb76715f0810588c7b`.
- `database-before-candidate.sql`: SHA-256
  `05338be17a3f342e6cd98c30a8ec688434b92ac960d73a2c4b3dcff390c82c45`.
- Restauració de DB comprovada en `maxcache_restore_probe`: 76 taules i
  l'usuari inicial. Arxiu de fitxers llegible i configuració amb hash coincident.
- Còpia local prèvia a les proves: manifest verificat a l'arxiu intern.
- Còpia local abans d'aquests canvis: 70 fitxers verificats a l'arxiu intern.
- Còpia de fitxers i DB abans del segon candidat al host Docker:
  `backup/pre-cookie-fix/` del laboratori. El tar és llegible;
  SHA-256 site `03f265038bb0e9c8dfacb0a137f3245436d9caaa7d204e44acbcc39f020ad141`,
  DB `771c11f0254b608cdf357847e61422e2782420165e4db4a40ed232f919d23f5e`.

[Evidència final sanititzada](qa/joomla6-2026-10-11/evidence.json): 101 comprovacions,
cap fallada. Els checks locals `php tests/run.php` (93 helper + 184 runtime =
277 assertions) i `tests/route-report.sh` passen, igual que lint PHP, manifests
XML i `git diff --check`.

[Abans de cookies/exclusions Apache](qa/joomla6-2026-10-11/evidence-before-cookie-fix.json):
88 comprovacions, quatre fallades. L'HTML antic es servia amb HTTP 200.
[Abans del matcher ancorat](qa/joomla6-2026-10-11/evidence-before-anchor-fix.json):
98 comprovacions, sis fallades: cinc del matcher i una del runner perquè la
passada anterior havia deixat un artifact públic. S'han corregit totes dues
causes; no s'han relaxat les assertions de seguretat.

El laboratori es conserva amb els volums, paquet, DB i fitxers de prova.
En acabar es retorna el host del laboratori al seu estat inicial aturat. Per reprendre les
proves, seguir [tests/integration/README.md](../tests/integration/README.md).
Els secrets generats només es fan servir al laboratori, mai a l'informe ni
a l'evidència. No hi ha hagut proves sobre clients de producció.

Límits: no s'ha provat `mod_maxcache`, HTTPS real, YOOtheme, RSForm ni Admin
Tools. La lectura Apache usa un artifact HTTPS sintètic: no prova la
generació HTTPS ni totes les possibles configuracions de servidor.
La compatibilitat Joomla 5 del càlcul es basa en codi real i tests del helper,
no en una passada completa d'integració Joomla 5.

Acció recomanada aquesta setmana: conservar aquest candidat i validar el
binari `mod_maxcache` amb HTTPS en staging abans de preparar cap release.
Ni les rutes dels clients ni les seves incidències de configuració s'han
donat per resoltes amb aquestes proves de laboratori.
