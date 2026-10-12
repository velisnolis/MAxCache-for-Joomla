# Validació del candidat `4efa2f3`

2026-10-12. Estat: `DONE`. Cobreix els tres commits de codi que el 2026-10-11 només
s'havien provat en local: `onBeforeRespond` a `Priority::MIN`, `tracking_query_params`
i `SnippetParams`. No s'ha fet release ni s'ha instal·lat el candidat a cap client.

## Laboratori Joomla 6: 121/121 en dues passades

Mateix laboratori aïllat que el 2026-10-11: Joomla 6.1.4, PHP 8.4.26, Apache sense
`mod_maxcache`. Còpia de fitxers (13.449 entrades) i dump de 76 taules abans
d'instal·lar, amb SHA-256 al directori `backup/` del laboratori.

- Paquet construït des del checkout sense tocar `updates/`; SHA-256
  `b1289dd4420f80ddcfd31a0f7ebdbd4feebc6c3b5efe70ca39ad1ccc742b41af`.
- Runner: `tests/integration/joomla6.php` del checkout. És idèntic al que es va fer
  servir el 2026-10-11, llevat d'un comentari.
- Els cinc fitxers comprovats, `SnippetParams.php` inclòs, coincideixen amb el checkout.
- [Passada 1](qa/2026-10-12/lab-evidence-run1.json) i
  [passada 2](qa/2026-10-12/lab-evidence-run2.json): 121 comprovacions, cap fallada.
  Inclouen la coherència de hash entre admin, estat i console real després de
  centralitzar els paràmetres del snippet.

Els contenidors del laboratori s'han tornat a aturar.

## `tracking_query_params` amb `mod_maxcache`: 60/60

Canary sintètica en dos sites de client al servidor CloudLinux, executada com a
usuari de cada compte: site C (un sol idioma, `host-sef`) i site D (multiidioma,
`host-language-sef` amb `/ca/` i `/es/`). Tots dos amb `.htaccess` d'Admin Tools.
Les peticions van a l'origen amb SNI i TLS validat.

Cada canary té tres variants generades amb el `SnippetBuilder` del candidat:

- `tracking`: llista `utm_source utm_medium utm_campaign gclid fbclid`.
- `strict`: sense llista, el comportament actual.
- `off`: `MaxCache Off`, control que demostra que el fallback funciona.

Cada pàgina té un fallback (`FALLBACK`) i un artifact a la cache sintètica (`CACHED`),
de manera que la resposta diu qui l'ha servida.

| Petició | Esperat | Site C | Site D (arrel, `/ca/`, `/es/`) |
|---|---|---|---|
| sense query, `strict` i `tracking` | cache | ✓ | ✓ |
| `?utm_source=…` a `strict` | PHP | ✓ | ✓ |
| `?utm_source=…`, `?gclid=…`, combinacions | cache | ✓ | ✓ |
| `?utm_source=x&start=10` i a l'inrevés | PHP | ✓ | ✓ |
| `?start=10`, `?p=customizer&utm_source=x` | PHP | ✓ | ✓ |
| `?utm_source=x` amb cookie `joomla_user_state` | PHP | ✓ | ✓ |
| `off` sense query | PHP | ✓ | ✓ |

Resultats: [site C, 15/15](qa/2026-10-12/site-c-canary-probe.txt) i
[site D, 45/45](qa/2026-10-12/site-d-canary-probe.txt).

Es confirma el comportament que només constava a la documentació de CloudLinux.
Amb els mateixos noms a `MaxCacheQSAllowedParams` i `MaxCacheQSIgnoredParams`, una
query feta només de paràmetres de seguiment dona un `{QS_SUFFIX}` buit i rep
l'artifact canònic. Qualsevol altra clau manté el sufix i acaba a PHP.

Dos casos informatius, coherents amb el regex del mode Apache:

- `?utm_source` sense valor rep la cache.
- `?UTM_SOURCE=x` no: els noms distingeixen majúscules.

## Còpia i retirada

Abans de crear cada canary: còpia del `.htaccess` principal, SHA-256 de `.htaccess` i
`configuration.php` i empremta del llistat de la cache real, en una carpeta privada
del compte fora del web. Després de la prova, la canary s'ha mogut fora del document
root. Hashes i llistat de cache idèntics als d'abans, portada 200 i ruta de la canary 404.
No s'ha canviat el plugin instal·lat, els params ni el `.htaccess` principal, ni s'ha purgat.

La versió del mòdul no s'ha tornat a consultar avui; l'inventari del 2026-10-11 indicava
`mod_maxcache` 1.2.5.
