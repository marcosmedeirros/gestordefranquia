# NBA Players Index (Local Fallback)

Place a file named `leaguePlayerList.json` here to override remote fetching when the NBA CDN blocks access.

Accepted formats:
- Original NBA JSON structure (from https://cdn.nba.com/static/json/staticData/player/leaguePlayerList.json):
  ```json
  { "league": { "standard": [ { "personId": "201939", "firstName": "Stephen", "lastName": "Curry" }, ... ] } }
  ```
- Flattened array of players:
  ```json
  [ { "personId": "201939", "firstName": "Stephen", "lastName": "Curry" }, ... ]
  ```

Once the file is in place, `migrate-sync-nba-player-ids.php` will use it automatically when remote sources are unavailable.
