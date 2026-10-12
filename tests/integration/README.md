# Proves optatives amb Joomla 6 real

`joomla6.php` s'executa dins d'una instal·lació aïllada de Joomla 6.1.4 amb
PHP/cURL i Apache `mod_rewrite`. No forma part de `tests/run.php` i no s'ha
d'executar en un site existent de client.

El runner exigeix `MAXCACHE_ISOLATED_JOOMLA6=1`, el directori `/var/www/html`
i el nom de site exacte `MAxCache Joomla 6 isolated lab`. Crea contingut,
grups i usuaris de prova, renova les seves contrasenyes, activa/configura
MAxCache, purga només la cache del laboratori i aplica el snippet a `.htaccess`.
Les contrasenyes i les cookies es mantenen en memòria del procés.

## Preparació

Cal una instal·lació nova amb volums i DB independents. La validació del
2026-10-11 fixa aquesta imatge de Joomla:

```text
joomla@sha256:ae220b4caddec280f4e3247bc4f77e9ddc508dfa503273121ad9a8011eaad5c3
```

Instal·lar el paquet candidat de MAxCache i el plugin de prova de `fixture/`
amb `php cli/joomla.php extension:install --path=...`, com a `www-data`.
El ZIP de `fixture/` conté `maxcachelab.xml`, `services/` i `src/` a l'arrel.
Aquest plugin no s'ha d'incloure mai al paquet de MAxCache.

No executar `docker/build-package.sh` directament al checkout per a aquesta
prova: també reescriu el feed. Els quatre ZIP d'extensions i el ZIP exterior
s'han de construir en un staging nou, sense tocar `updates/` ni les versions.

Fer i verificar una còpia de fitxers i DB abans d'instal·lar el candidat.
Els fitxers de proves han d'estar fora del document root. El web s'ha de
publicar només al loopback, no a Internet. No reutilitzar credencials de clients.

## Execució al laboratori conservat

El laboratori és un host Docker dedicat a la xarxa interna. La web de prova es troba
a `maxcache-j6-20261011-web` i la DB a `maxcache-j6-20261011-db`.
Si el host o aquests contenidors estan aturats, cal arrencar-los explícitament.
No cal arrencar ni modificar els Joomla anteriors per executar el runner.

```bash
docker cp tests/integration/joomla6.php \
  maxcache-j6-20261011-web:/tmp/maxcache-joomla6.php
docker exec --user www-data --env MAXCACHE_ISOLATED_JOOMLA6=1 \
  maxcache-j6-20261011-web php /tmp/maxcache-joomla6.php
```

Cada execució purga només la cache dedicada després dels guards de site i
versió, renova els usuaris sintètics i es pot repetir. És una prova
mutant: mai s'ha de dirigir a producció. El resultat sanititzat queda a
`/tmp/maxcache-joomla6-evidence.json` dins del contenidor; no conté tokens,
cookies, contrasenyes ni cossos complets de resposta.

## Resultat esperat i límits

El runner comprova el writer, les capçaleres rebudes per HTTP, l'autenticació
de l'administrador, ACL reals, CSRF, fitxers plain/gzip, col·lisions amb query
i les regles Apache instal·lades. El plugin de prova només simula contingut
i polítiques de resposta; no substitueix l'aplicació, les sessions ni l'ACL.

El snippet Apache actual apunta a `index-https.html`. Per provar la lectura
estàtica es crea un fitxer sintètic amb aquest nom; no es presenta com un
artifact HTTPS generat pel writer. La generació plain/gzip es prova per HTTP.
No es prova aquí el binari `mod_maxcache`, HTTPS real ni plugins de tercers.

A 2026-10-11, les 121 comprovacions passen dues vegades amb el candidat final. Inclouen
la cookie real del frontend després d'aplicar el snippet des de l'admin,
exclusions ancorades, HTML antic a reset/remind, tokens diferents entre
visites i regex Apache amb espais, cometes i barres. No eliminar aquestes
proves ni tractar el `200` estàtic com una resposta viva de Joomla.

Si el conjunt d'exclusions conté totes dues cometes literals (`"` i `'`),
Apache no pot delimitar-lo amb seguretat: el snippet desactiva la lectura
estàtica i indica usar `\x22`/`\x27` al regex. La prova verifica que Joomla
continua responent i que reaplicar un patró segur recupera la lectura estàtica.
El writer continua actiu per a pàgines públiques no excloses en aquest cas.

Vegeu `docs/joomla6-validation-2026-10-11.md` i l'evidència JSON corresponent.

La passada inicial de 101 checks queda a `docs/joomla6-validation-2026-10-11.md`.
La validació final ampliada inclou aplicació i estat de l'admin amb gzip on/off,
la comanda CLI real `maxcache:status --format=json` en un procés independent,
estat obsolet en canviar `write_gzip` i lectura Apache d'un gzip del writer real.
La comparació entre clients també cobreix l'ordre estable de cookies i l'exclusió
de la sessió interna CLI, que no és una cookie HTTP.
Evidència i paquet final a `docs/gzip-mime-patch-validation-2026-10-11.md`.
