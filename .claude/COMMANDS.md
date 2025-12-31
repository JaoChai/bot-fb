# Commands Reference

## Backend
```bash
cd backend && composer install && php artisan serve
php artisan test                    # Run tests
php artisan tinker                  # Debug REPL
```

## Frontend
```bash
cd frontend && npm install && npm run dev
npm run build                       # Production build
npm run lint                        # Check errors
npx shadcn add [component]          # Add UI component
```

## Railway
```bash
railway logs                        # View logs
railway logs --service backend      # Backend only
railway variables                   # Check env vars
railway up --service backend        # Deploy
```

## Git
```bash
git status && git diff              # Before commit
git log --oneline -10               # Recent commits
```

## Debug
```bash
curl https://api.botjao.com/api/health
```
