# code-server on the VPS — Tailscale-only (no public exposure)

> Setup runbook. Puts browser VS Code on the build box, reachable **only** over your tailnet with real
> HTTPS, via Tailscale Serve. code-server binds to localhost; Tailscale proxies it. No public port, and
> **no Caddy/nginx involvement** — this avoids the 80/443 conflict between Caddy and the existing nginx.
> Most steps need sudo (you'll be prompted). Run as `dev` on the VPS.

## Goal
Edit/run NovFora from a browser IDE on the VPS — including a terminal to drive `claude`, the gates, and
tmux — accessible from any of your devices on the tailnet, and from nowhere else.

## Prerequisites (one-time, in the Tailscale admin console)
- **MagicDNS** enabled.
- **HTTPS Certificates** enabled (Settings → Features). `tailscale serve` needs this to mint the cert.
- Note this machine's tailnet name: `tailscale status --json | grep -i dnsname` (e.g. `vps.tailnet-xxxx.ts.net`).

## Steps

### 1. Bind code-server to localhost only
Edit `~/.config/code-server/config.yaml`:
```yaml
bind-addr: 127.0.0.1:8080
auth: password
password: <set-a-strong-one>   # this is the install-generated value unless you change it
cert: false                    # TLS is terminated by Tailscale, not code-server
```
View the current generated password if you want to keep it: `cat ~/.config/code-server/config.yaml`
**Verify:** `bind-addr` is `127.0.0.1`, never `0.0.0.0`.

### 2. (Re)start the service
```bash
sudo systemctl enable --now code-server@dev
sudo systemctl restart code-server@dev
ss -tlnp | grep 8080            # must show 127.0.0.1:8080, NOT 0.0.0.0:8080
systemctl is-active code-server@dev
```

### 3. Expose it over Tailscale (HTTPS, tailnet-only)
```bash
sudo tailscale serve --bg 8080          # proxies https://<machine>.<tailnet>.ts.net -> localhost:8080
tailscale serve status                  # confirm the mapping
```
If your Tailscale version rejects that form, use the explicit one:
`sudo tailscale serve --bg --https=443 http://127.0.0.1:8080` (check `tailscale serve --help`).
**Do NOT use `tailscale funnel`** — funnel publishes to the public internet. We want `serve` (tailnet-only).

### 4. Free the edge (resolve the Caddy/nginx conflict)
code-server no longer needs Caddy. Confirm what owns 80/443 and stop the loser:
```bash
systemctl status caddy nginx --no-pager
sudo ss -tlnp | grep -E ':(80|443)\b'
```
If Caddy was installed only for code-server, disable it so it stops fighting nginx for 443:
```bash
sudo systemctl disable --now caddy
```
(Keep Caddy only if it's intentionally fronting the public website — but then nginx and Caddy still
can't both hold 443; pick one for the public edge. That's a separate decision from code-server.)

### 5. Use it
Browse to `https://<machine>.<tailnet>.ts.net` from any device signed into your tailnet, log in with the
code-server password. Open `~/novfora`, use the integrated terminal for `claude`, `tmux`, and the gates.
Optional: install Intelephense from the OpenVSX marketplace for PHP/Laravel support.

## Security checklist (the point of going Tailscale-only)
- [ ] `bind-addr` is `127.0.0.1` — code-server is never on a public interface.
- [ ] Reached only via `tailscale serve` (not `funnel`); no inbound 80/443 rule added for it.
- [ ] code-server `auth: password` stays on (defense in depth behind the tailnet).
- [ ] `sudo ss -tlnp` shows nothing new listening on a public address.

## Done when
- `https://<machine>.<tailnet>.ts.net` loads code-server from a tailnet device and is unreachable from off-tailnet.
- `ss` shows code-server bound to `127.0.0.1:8080` only.
- Exactly one service owns public 443 (nginx), and Caddy is not conflicting.
