# Project review

Reviewed the CodeIgniter REST Server tree at `b0229a1` (the library docblock calls this 3.0.0; the changelog still has an unreleased section on top of 2.7.2). The repo is the REST library, its config, and the sample controllers. CodeIgniter itself is not vendored here, and there is no test suite.

## Verdict

The mapper from HTTP verb to `resource_get` / `resource_post` is sound, and database values go through the query builder. The defaults and the body parsers are not safe to put on an open network. Several checks trust the client for a value the server should have decided itself.

The follow-up on this branch changes the items under "Fixes". Items under "Left alone" stay, with the reason.

## What is already in good shape

- API key, limit, and access lookups bind their values through the query builder. Table names come from config, not from the request.
- The IP blacklist matches the client address as a literal (`\Q...\E`), so a crafted address cannot change the pattern.
- Unknown verbs return 405. Output format names have to be in the supported list before a `?format=` value is accepted.
- The JSONP callback has to look like an identifier, so a callback cannot close the call and start a new statement.
- Private API keys can be pinned to a list of IP addresses.

## Fixes

### 1. Serialized request bodies can build PHP objects

`Format::_from_serialize()` calls `unserialize()` on the raw body when `Content-Type` is `application/vnd.php.serialized`. That format is enabled by default. A payload that names a class already loaded in the app runs that class's wakeup path. This is PHP object injection.

Fix: unserialize with `allowed_classes` disabled on PHP 7 and newer, reject `O:` / `C:` payloads on older PHP, and refuse the body if any object remains. `REST_Controller` turns that failure into HTTP 400.

### 2. XML request bodies still process a doctype

`Format::_from_xml()` loads the body with `simplexml_load_string()` and `LIBXML_NOCDATA` only. That flag does not turn off external entities. On PHP before 8.0, a doctype can make the parser read a local file or a remote URL. A doctype can also expand internal entities until the process runs out of memory.

Fix: reject a body that declares a doctype, pass `LIBXML_NONET`, and on PHP before 8.0 disable the external entity loader around the parse.

### 3. Digest auth trusts the URI and the nonce from the client

`REST_Controller::_prepare_digest_auth()` rebuilds the expected hash from `$digest['uri']` and `$digest['nonce']`. Neither is compared with the request that actually arrived. A valid `Authorization` header for `GET /public` is also valid for `GET /admin`, because the server hashes the URI written in the header. The nonce is `uniqid()` on the challenge and is never checked later, so a captured header works until the password changes. The username is read before the code checks that the field exists.

The same function asks `_check_login($username, TRUE)` for the stored HA1. That shortcut runs only when `auth_source` is empty. The shipped default is `ldap`, so digest against `rest_valid_logins` does not do what the README describes.

Fix: require the digest fields, accept only `qop=auth`, hash the real request method, and require the digest URI to be the request URI (or that URI with the front-controller script name removed). Issue a nonce of `timestamp:hmac` and reject nonces outside a one-hour window. Compute HA1 from `rest_valid_logins` unless `auth_source` is `ldap` or `library`. Compare the response with `hash_equals`.

### 4. New API keys throw away most of the random bytes

`Key::_generate_key()` does `base_convert(bin2hex(random_bytes(64)), 16, 36)`. `base_convert()` parses through a float. A 128-digit hex string does not fit in a float, so the key is not the 64 bytes that were generated. The sample comments even tell you the column is only 40 characters, and the code then keeps a prefix of this weakened value.

Fix: keep `bin2hex()` of the random bytes and cut that string to `rest_key_length`.

### 5. The sample config ships a known login, and basic auth does not use it

`application/config/rest.php` sets `rest_valid_logins` to `admin` / `1234` and `auth_source` to `ldap`. The README tells you to turn on basic auth and edit `rest_valid_logins`. With the shipped `auth_source`, basic auth never reads that array. It calls LDAP. Digest is the same. The config comment also publishes `md5('admin:REST API:1234')`.

Fix: leave `rest_valid_logins` empty and `auth_source` empty, so a new copy fails closed until someone sets a password, and so the README path uses the config logins. LDAP stays available by setting `auth_source` to `ldap`.

### 6. The IP whitelist treats "no address" as local

`_check_whitelist_auth()` always appends `127.0.0.1` and `0.0.0.0`. CodeIgniter's `ip_address()` returns `0.0.0.0` when the client address is missing or fails validation. Those requests pass the whitelist.

Fix: stop adding `0.0.0.0`. Keep `127.0.0.1`, and add `::1` so IPv6 loopback still works.

### 7. A valid API key can skip the whitelist

The whitelist runs only inside the block that also decides whether to require a login. If keys are enabled, the key is valid, and `allow_auth_and_keys` is false, that whole block is skipped. The whitelist is skipped with it. `allow_auth_and_keys` defaults to true, so this shows up when an operator turns that flag off and still expects the whitelist to apply.

Fix: when the whitelist is enabled and no per-route auth override already ran, check the whitelist on its own.

### 8. LDAP usernames are pasted into the search filter

`_perform_ldap_auth()` searches for `uid=$username`. The username is the raw basic-auth user. `*`, `(`, `)`, and `\` change the filter. A failed `ldap_connect()` also falls through into `ldap_search()`. An empty password is still handed to `ldap_bind()`, which some servers treat as an anonymous bind.

Fix: escape the username for an LDAP filter, return before the search when connect fails or the extension is missing, and reject an empty password. The debug line that referenced an undefined `$ldaphost` now logs the configured host.

### 9. Basic auth reads one header and decodes another

`_prepare_basic_auth()` decides the header is present by reading `HTTP_AUTHENTICATION`, then base64-decodes `HTTP_AUTHORIZATION`. On a server that sets only one of those, the fallback never sees the password. `explode(':', ...)` also splits the password on every colon, so a password that contains `:` does not match.

Fix: read `HTTP_AUTHORIZATION`, then `HTTP_AUTHENTICATION`, and split the decoded value on the first colon only.

### 10. HTML output is not escaped

`Format::to_html()` passes cell values straight to the CodeIgniter table library, which prints them as HTML. A client that asks for `format=html`, or sends `Accept: text/html`, gets those values in a page. Data that contains a tag becomes markup.

`to_xml()` has the same class of problem on PHP 8: `htmlspecialchars()` is called with whatever type the value has, and an integer is a type error. The root element name is also interpolated into the XML string, so a caller-supplied name can break out of the element.

Fix: escape HTML cells and headings, cast XML text to string, and keep element and attribute names to a safe token.

### 11. Accept negotiation picks `php` for a serialized body

`_detect_output_format()` uses `strpos()` of the format name inside the `Accept` header. `application/vnd.php.serialized` contains `php`, and `php` is checked before `serialized`, so a client that asks for the serialized mime gets `var_export` output. The special case for `html` and `xml` is worth keeping: a normal browser `Accept` lists both, and the code correctly falls through to the default format instead of returning an HTML table.

Fix: when more than one format name matches, keep the longest name. If both `html` and `xml` match, keep ignoring both so browsers still get the default format.

### 12. The sample key API turns limits off and can raise its own level

`Key::index_put()` uses `1` when `ignore_limits` is missing or not a digit string. The schema default is `0`. A key created with no extra fields does not count against rate limits. On PHP 8, `ctype_digit()` does not accept the integers `json_decode` produces, so a numeric JSON `1` also fell through to that default.

`index_put()` and `level_post()` store the submitted level as given. The route is limited to level 10, but that caller can write a higher level onto any key, including its own.

Fix: default `ignore_limits` to `0`, accept integer and digit-string flags, and never store a level above the caller's level.

### 13. Access logs store secrets, and early rejects crash when logging is on

`_log_request()` serializes `_args`. That array includes request headers, so `Authorization`, the API key, and a field named `password` are written into `logs.params`. `rest_logs_json_params` defaults to PHP `serialize`, which is a bad format to store and later unserialize.

The blacklist runs before the database connection is opened. `response()` still calls `_log_response_code()` when logging is enabled, and the destructor calls `_log_access_time()`. Both use `$this->rest->db`. A blacklisted client, with logging turned on, gets a PHP error instead of the 401.

Fix: redact secret parameter names before insert. Skip the log update when there is no insert id or no connection. The `api_key` column is unchanged; that column is the reason the log exists.

### 14. Rate limits can be passed by overlapping requests

`_check_limit()` reads `count`, decides the request is under the limit, then writes `count + 1`. Two requests can both read the same count and both be allowed. The update is also not limited to rows that are still under the limit.

Fix: `UPDATE ... SET count = count + 1 WHERE count < limit`, and allow the request only when that update changes a row. The first insert is still not unique. The published table has no unique key on `(uri, api_key)`, so two brand-new requests can still insert two rows. That needs a schema change and is left alone.

### 15. Controller exceptions are rendered as an HTML error page

`_remap()` catches `Exception` and calls CodeIgniter's `show_exception()`. The client gets an HTML page with the message and the stack, and the status is not the JSON error the rest of the API uses. `rest_handle_exceptions` defaults to true.

Fix: return a JSON 500. Include the exception message only when `ENVIRONMENT` is `development`.

### 16. A bad API key on `OPTIONS` exits with an empty 200

After a failed key check, `_remap()` calls `exit` when the verb is `options`. The client gets an empty 200 instead of the 403 every other verb gets. CORS preflight is already handled in `_check_cors()`, which runs earlier and returns when `check_cors` is on.

Fix: delete that `exit`. Add `Authorization` and the configured key header to the CORS allow-list, and send `Vary: Origin` when the origin is echoed. Also send `X-Content-Type-Options: nosniff` on API responses.

### 17. Smaller parser and sample bugs

- `to_json()` writes an invalid callback into `$data['warning']`. If the payload is not an array, that assignment is a type error on PHP 8, and the callback text was echoed back. Invalid callbacks are now ignored for lists, and the warning no longer contains the submitted text.
- CSV cells that start with `=`, `+`, `-`, or `@` are prefixed so a spreadsheet does not treat them as formulas. Numeric values are left alone.
- The third sample user stores hobbies as a bare nested array (`0 => [...]`) because the key `hobbies` is missing. The sample record now has that key.
- The README says `response([])` is a 404. The code only uses 404 when the data is `NULL` and no status was passed. An empty array is a body and stays 200. The README now matches the code.
- The invalid-key error uses `sprintf(..., $this->rest->key)`. When the key exists but the private-IP check fails, the stored key is sent back to the client. The response no longer includes it.
- Login passwords from `rest_valid_logins` are compared with `hash_equals`.
- `WWW-Authenticate` strips CR, LF, and quotes from the realm and the nonce.
- Stray output buffered inside `response()` is discarded so a notice cannot be prefixed onto the body.

## Left alone

- JSONP via `?callback=` stays, because the README documents it. The callback still has to be an identifier. A session-authenticated API should not use it. `nosniff` is set so a browser is less willing to treat the JSON body as a script.
- `enable_emulate_request` stays on. `POST` with `_method` or `X-HTTP-Method-Override` can become `PUT` or `DELETE`. That is a CSRF problem if the API uses the session auth mode. Turn the flag off when browsers can submit forms to the API.
- API keys are still read from the merged parameter array, so `?X-API-KEY=` works as well as the header. Existing clients depend on that. Query strings are copied into access logs and `Referer`. Prefer the header.
- Digest nonces expire after one hour. They are not stored, so a header can be replayed inside that hour. Set CodeIgniter's `encryption_key`. If it is empty, the nonce key is derived from the application path and the realm, which is weaker.
- Digest HA2 uses the wire method, not the emulated method. A client that hashed the overridden verb will be rejected and challenged again.
- `Key` is a sample admin controller. It should not be reachable on a public site even after the level cap.
- The demo page loads jQuery 1.12.0 from a third-party CDN.
- Log rows still store the key in the `api_key` column. Parameter dumps no longer copy it a second time. New installs that want JSON logs can set `rest_logs_json_params` to true. The default remains serialized PHP so existing log readers do not break. The sample `logs.method` column is described as `VARCHAR(6)`, which cannot hold `OPTIONS`. The comment now says `VARCHAR(10)`. A database created from the old comment needs that column widened by hand.
- There is no unique key on the limits table, so the first request for a new URI can still be counted twice under concurrency.

## Verification

The follow-up commit adds `tests/format_security_test.php` for the format parser (object payloads, doctypes, XML names, HTML escaping, CSV formula prefixes, and JSONP callbacks) and syntax-checks the PHP with `php -l`. This repo does not contain CodeIgniter, so the controller changes cannot be exercised as HTTP requests from this tree.
