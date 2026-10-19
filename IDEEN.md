# Ideen & ToDo

Offene Punkte und Ideen für spätere Versionen. Erledigtes wird hier gestrichen und ins
[CHANGELOG](CHANGELOG.md) übernommen.

## ToDo

### Feature: Kategorien
Eigene Kategorien anlegen (z. B. „Wandern“, „Museum“, „Mit Kindern“) und Ziele zuordnen.
- Abgrenzung zu Tags klären: Kategorie = eine feste Einordnung pro Ziel (ggf. mit Icon/Farbe), Tags = freie Schlagworte. Alternativ Tags zu Kategorien ausbauen.
- Schema nur additiv: neue Tabelle `Category` (id, name, icon/farbe, createdBy, …), Spalte `Destination.categoryId` oder Zuordnungstabelle.
- Filter nach Kategorie in Suche und Dashboard.
- Gehören Kategorien einem Benutzer? (Tags sind derzeit global und nur vom Admin änderbar.)

### Feature: Freigabe von Zielen und Kategorien zwischen Benutzern
Ziele (einzeln oder eine ganze Kategorie) mit anderen Benutzern teilen.
- Neue Tabelle z. B. `Share` (resourceType, resourceId, ownerId, sharedWithId, permission `READ`/`WRITE`, createdAt).
- Alle Abfragen mit `createdBy = ?` (api.php, u. a. Ziele, Bilder) um freigegebene Ziele erweitern; Schreib-/Löschrechte gesondert prüfen.
- Persönliche Felder (`privateNotes`, ggf. Favorit/Besucht) pro Benutzer trennen – sonst sieht/ändert der andere sie mit.
- UI: Freigabe-Dialog auf der Detailseite, Kennzeichnung „geteilt von …“ im Dashboard, Liste „Mit mir geteilt“.

## Ideen

- **Kartenansicht**: `latitude`/`longitude` existieren im Schema, werden aber nicht genutzt. Koordinaten erfassen (Browser-Geolocation „Aktueller Standort“) und Ziele auf einer Karte zeigen – ohne externe Bibliothek z. B. als Link zu OpenStreetMap; eine eingebettete Karte bräuchte eine Bibliothek (Abwägung wegen „keine Abhängigkeiten“).
- **Papierkorb**: Ziele werden per `deletedAt` nur weich gelöscht – Ansicht zum Wiederherstellen bzw. endgültigen Löschen.
- **Besuchsdatum / Notizen pro Besuch**: statt nur „besucht ja/nein“ ein Datum oder eine kleine Besuchshistorie.
- **Offline-Fähigkeit**: Service Worker für die PWA (App-Shell cachen, Liste zuletzt geladener Ziele offline anzeigen).
- **Import / weitere Exportformate**: Den JSON-Export (`GET destinations/export`, `formatVersion` 1) wieder einlesen, z. B. beim Umzug auf einen anderen Server; dazu ggf. CSV oder GPX. Fotos bräuchten ein eigenes Archivformat (ZIP).
- **Paginierung**: Die Zielliste lädt derzeit alles auf einmal und filtert im Browser – bei sehr vielen Zielen seitenweise laden und wieder serverseitig filtern (die API-Parameter dafür gibt es).
