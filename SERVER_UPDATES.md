# Server updates

The production server runs Debian 9 with Docker 19.03 and Docker Compose 1.8.
Source builds must not run on that host. GitHub Actions builds an amd64 image with a
modern builder and publishes it to GitHub Container Registry (GHCR).

## Image publication

Every push to `main` publishes:

- `ghcr.io/juvantusik/samzaberu-bot:latest`
- `ghcr.io/juvantusik/samzaberu-bot:sha-<short-commit-sha>`

The runtime stage remains based on `node:22-bullseye-slim`, which is compatible
with the existing production deployment.

## One-time server setup

1. Create a GitHub personal access token (classic) with only `read:packages`.
2. Log in without placing the token in shell history:

   ```bash
   read -rsp "GitHub Packages token: " GHCR_READ_TOKEN && echo
   printf '%s' "$GHCR_READ_TOKEN" | sudo docker login ghcr.io -u juvantusik --password-stdin
   unset GHCR_READ_TOKEN
   ```

3. Set this image in `/home/tech/samzaberu-bot/docker-compose.server.yml`:

   ```yaml
   image: ghcr.io/juvantusik/samzaberu-bot:latest
   ```

4. Install `ops/update-server.sh` as `/home/tech/update-samzaberu.sh` and make it executable.

## Routine update

After the GitHub Actions workflow has completed successfully:

```bash
/home/tech/update-samzaberu.sh
```

The script:

- tags the currently running image as `samzaberu-app:rollback`;
- pulls the new private image;
- recreates only `samzaberu-app`;
- leaves PostgreSQL and the legacy PHP bots untouched;
- waits for Docker health status;
- restores the previous image automatically if the new container fails.

## Manual rollback

If a rollback is needed after an apparently healthy release:

```bash
sudo docker tag samzaberu-app:rollback ghcr.io/juvantusik/samzaberu-bot:latest
cd /home/tech/samzaberu-bot
sudo docker-compose -f docker-compose.server.yml up -d --no-deps --force-recreate samzaberu-app
```
