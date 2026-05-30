# TaskFlow WhatsApp — Workforce Management OS

> Manage your entire workforce through WhatsApp. Zero app installs for employees.

## Stack
- **Backend**: Laravel 12 (PHP 8.2+)
- **Frontend**: React 19 + TypeScript + Vite
- **Database**: PostgreSQL 16
- **AI**: Claude API (Anthropic)
- **WhatsApp**: Meta Business Cloud API
- **Hosting**: Render.com

## Quick Deploy to Render

1. Push this repo to GitHub
2. Go to [dashboard.render.com](https://dashboard.render.com)
3. New > Blueprint > connect this repo
4. Render auto-reads `render.yaml` and creates all 3 services
5. After deploy, run migrations via Render Shell:
   ```
   php artisan migrate --force
   php artisan db:seed --force
   ```
6. Set secret env vars in Render dashboard:
   - `ANTHROPIC_API_KEY`
   - `WHATSAPP_TOKEN`
   - `WHATSAPP_PHONE_NUMBER_ID`

## Default Login
- Email: `admin@taskflow.com`
- Password: `Admin@123`

## WhatsApp Commands (for employees)
| Command | Action |
|---------|--------|
| `START` | Begin task |
| `UPDATE` | Send progress |
| `COMPLETE` | Mark done |
| `DELAY` | Report delay |
| `ESCALATE` | Flag issue |
| `SCORE` | View APIX score |
| `STATUS` | Active tasks |
| `HELP` | Show menu |

## APIX Score Formula
```
APIX = (Completion × 0.30) + (Timeliness × 0.25) + (Quality × 0.20) + (Consistency × 0.15) + (Manager Rating × 0.10)
```

## Folder Structure
```
taskflow/
├── render.yaml              # Render deployment config
├── taskflow-api/            # Laravel backend
│   ├── app/Domain/          # Business logic (DDD)
│   │   ├── Task/            # Task management
│   │   ├── User/            # User management
│   │   ├── AI/              # Claude API integration
│   │   └── WhatsApp/        # WA command handling
│   ├── app/Jobs/            # Queue workers
│   ├── database/migrations/ # DB schema
│   └── routes/api.php       # API endpoints
└── taskflow-dashboard/      # React frontend
    └── src/
        ├── pages/           # Dashboard + Login
        ├── store/           # Zustand state
        └── shared/api/      # Axios client
```

## Support
Built for UIC Group, Ahmedabad. Designed to compete with Asana/Monday.com through WhatsApp.
