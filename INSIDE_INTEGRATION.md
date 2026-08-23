# Inside 2.0 authentication for SamZaberu

This branch adds the protected API namespace:

```text
/api/inside/samzaberu
```

The browser never supplies an operator ID. SamZaberu validates the bearer token against Inside and derives both the employee identity and restaurant scope on the server.

## Runtime settings

```dotenv
INSIDE_API_BASE_URL=http://<inside-api-host>/<api-base>/
INSIDE_API_TIMEOUT_MS=10000

# OUs: only GET /rests/personal
INSIDE_SAMZABERU_ASSIGNED_JOB_IDS=46

# Inside administrators: all SamZaberu restaurants
INSIDE_SAMZABERU_FULL_ACCESS_JOB_IDS=2,3

# Optional user-specific rollout overrides
INSIDE_SAMZABERU_ASSIGNED_USER_IDS=
INSIDE_SAMZABERU_FULL_ACCESS_USER_IDS=

# The old API trusts a browser-supplied operatorId and must remain disabled.
SAMZABERU_LEGACY_WEB_ENABLED=false
```

`INSIDE_API_BASE_URL` must be reachable from `192.168.103.250`. It must point to the same API used by Inside Frontend 2.0, where `GET auth` and `GET rests/personal` are available.

## Request flow

1. Inside sends its existing bearer token to the same-origin reverse-proxy path.
2. SamZaberu calls `GET auth` on Inside with that token.
3. The employee's `fact_job_id` is checked against the configured full/assigned lists.
4. Assigned users are scoped by `GET rests/personal`.
5. Every change is checked again against that server-side scope.
6. The SamZaberu journal records `inside:<user id>` and the employee's real name.

Any missing configuration, invalid token, unavailable Inside API, or unknown role fails closed.

## Reverse proxy

On the Inside IIS server, proxy:

```text
/samzaberu-api/{path}
    -> http://192.168.103.250:3080/api/inside/samzaberu/{path}
```

Preserve the `Authorization` header and limit direct access to port 3080 at the network layer.

## Deployment order

1. Build and test this branch without replacing production.
2. Configure the Inside API URL and role IDs on the SamZaberu server.
3. Deploy the protected SamZaberu image.
4. Configure and test the IIS reverse proxy.
5. Deploy the reviewed Inside frontend branch.
6. Confirm that the legacy API remains disabled.
