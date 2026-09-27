# Delvebound

An idle "expedition" RPG. You prepare your hero (spend stat points, equip gear, shop for potions, pick a
level-appropriate destination), then run the simulation. Your hero fights their way through a forest,
mine, or dungeon on their own, and you read a mail report and watch a replay of what happened. You share
the world and the leaderboard with six rule-based bot adventurers.

- **One Laravel 12 app** (PHP 8.2+) for everything.
- **Website:** Inertia.js + React + TypeScript + Tailwind at **http://delvebound.host** (`public/` is the
  web root). Pages switch without reloading.
- **Simulation:** `php artisan delve:run`, started by hand from a terminal. It talks to the website only
  through the database.
- **Database:** MySQL 8.0.
- **Runs on:** WSL2 Ubuntu 22.04. Repo root: `/var/www/vhosts/delvebound`.

## Status

Milestones M1–M4 (database, simulation, bots, website) are built, and M6 moved the whole project to one
Laravel codebase (the simulation was ported from Python to PHP). The design is the source of truth in
[DESIGN.md](DESIGN.md). Agent guidance is in [AGENTS.md](AGENTS.md).

## Setup (WSL2 Ubuntu 22.04)

### 1. Packages

```bash
sudo apt update
sudo apt install -y mysql-server apache2 libapache2-mod-php8.2 php8.2-cli php8.2-mysql php8.2-mbstring \
                    php8.2-xml php8.2-curl php8.2-bcmath php8.2-zip
sudo a2enmod rewrite
sudo systemctl enable --now mysql apache2      # needs systemd=true in /etc/wsl.conf
```

You also need [Composer](https://getcomposer.org/) and Node.js 20+ (for example through `nvm`).

### 2. Code, dependencies, and `.env`

```bash
cd /var/www/vhosts/delvebound
composer install
npm ci && npm run build                        # builds the React UI into public/build
cp .env.example .env
nano .env                                      # set DB_PASSWORD, DB_SIM_PASSWORD, DB_ADMIN_PASSWORD
php artisan key:generate
chgrp -R www-data storage bootstrap/cache && chmod -R g+rwX storage bootstrap/cache
```

Main keys: `DB_HOST`, `DB_PORT`, `DB_DATABASE=delvebound`, the `delve_web` / `delve_sim` / `delve_admin`
users and passwords, `APP_URL=http://delvebound.host`, `DISPLAY_TIMEZONE`, and `ALLOW_SIGNUP`. See
[DESIGN.md §12.2](DESIGN.md#12-project-layout-config-and-wsl-setup).

### 3. Database: create, import schema and seed data

```bash
sudo mysql < database/sql/bootstrap.sql         # creates DB "delvebound" + the delve_* users/grants
php artisan migrate --database=admin --force    # schema (skipped automatically if the tables exist)
php artisan db:seed --database=admin --force    # places, monsters, items, shop, 6 bots
sudo mysql < database/sql/grants_web.sql        # table-level grants for the website user
php artisan delve:check-content                 # should report no problems
```

### 4. Apache virtual host

```bash
sudo cp apache/delvebound.host.conf /etc/apache2/sites-available/
sudo a2ensite delvebound.host
sudo apache2ctl configtest && sudo systemctl reload apache2
```

The vhost sets `ServerName delvebound.host`, `DocumentRoot /var/www/vhosts/delvebound/public`, and
`AllowOverride All` (Laravel's `public/.htaccess` routes every request through `index.php`).

### 5. Hosts entry (Windows)

Edit `C:\Windows\System32\drivers\etc\hosts` as Administrator and add:

```
172.19.63.2 delvebound.host
```

Open http://delvebound.host and **sign up**. One account = one character, and the first account
becomes admin. If the site stops resolving after a Windows reboot, the WSL IP may have changed:
check it with `hostname -I` and update the hosts line.

## Running the simulation

Runs are **manual only**. Run one whenever you like, as often as you like:

```bash
cd /var/www/vhosts/delvebound
php artisan delve:run                          # real run: bots decide, every character travels, mail is written
php artisan delve:run --dry-run --seed=42      # preview only; nothing is saved
php artisan delve:balance-lab --place=greenwood --level=1 --trips=1000
```

If a run crashes or the terminal is closed mid-run, the next run refuses to start (exit code 2). Use
`--force-unlock`, then `--resume=<run_id>` (or `--abandon=<run_id>`). All flags are in
[DESIGN.md §10.2](DESIGN.md#10-simulation-command-delverun).

## Development

```bash
php artisan test                               # all tests (uses the delvebound_test database)
npx tsc --noEmit                               # TypeScript check
npm run dev                                    # Vite with hot reload while editing the UI
npm run build                                  # production build (run this when you're done)
```

Handy commands: `php artisan delve:create-account --username=… --password=… --character=…`,
`php artisan delve:make-admin --username=…`, and the dev reset
`php artisan migrate:fresh --drop-views --seed --database=admin` (drops everything).

## Documentation

- [DESIGN.md](DESIGN.md): full game design (schema, formulas, combat, loot, shop, bots, website,
  milestones, decisions log)
- [AGENTS.md](AGENTS.md): rules and conventions for AI agents working in this repo
