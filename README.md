# F-Commerce SaaS

Multi-tenant F-commerce / e-commerce platform for Bangladesh. Laravel 12 + Inertia + Vue 3 + TypeScript + Tailwind, installable PWA.
Implementation follows `F Commerce E commerce.md` phase by phase. See `docs/DEPLOYMENT.md` and `docs/PROGRESS.md`.

```
composer install && npm install
cp .env.example .env && php artisan key:generate   # set DB_* (or DB_CONNECTION=sqlite for local dev)
php artisan migrate --seed && npm run dev && php artisan serve
```
