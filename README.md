# Birthday Inbox

A Netlify-ready birthday inbox for Kyla Jane Hada-Ong. Guests can send a message and an optional short video file. Kyla Jane logs in privately to view submissions.

## Best Deployment: Netlify + Supabase

GitHub Pages cannot save submissions because it does not run backend code. Netlify can host the pages and run the serverless function in `netlify/functions/api.js`. Supabase stores the database rows and uploaded media.

## Setup

1. Create a Supabase project.
2. In Supabase SQL Editor, run `supabase.sql`.
3. Deploy this folder to Netlify.
4. In Netlify, add these environment variables:
   - `BIRTHDAY_PASSWORD`: `071703`
   - `SUPABASE_URL`: your Supabase project URL
   - `SUPABASE_SERVICE_ROLE_KEY`: your Supabase service role key
   - `SUPABASE_BUCKET`: `birthday-media`
5. Redeploy the Netlify site after saving environment variables.

## Pages

- `submit.html`: public page for guests.
- `login.html`: private login page.
- `index.html`: private inbox page.
- `/api`: Netlify function endpoint.

## Notes

- The private inbox can open offline after entering the passcode, but it will show no submissions without the backend.
- Sending submissions requires the deployed Netlify function and Supabase.
- Videos upload directly from the browser to Supabase Storage with signed upload URLs, so Netlify does not need to receive the whole video file.
- The video field is optional, and the sender page asks guests to keep videos around 20-30 seconds. The storage limit remains 2 GB as a safety cap.
- Keep `SUPABASE_SERVICE_ROLE_KEY` only in Netlify environment variables. Do not put it in frontend HTML or JavaScript.
- The old PHP/MySQL files are still present as an optional fallback for PHP hosting, but Netlify uses `netlify/functions/api.js`.
