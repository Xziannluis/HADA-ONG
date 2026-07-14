# Birthday Inbox

A Netlify-ready birthday inbox for Kyla Jane Hada-Ong. Guests can send a message and an optional Google Drive video link. Kyla Jane logs in privately to view submissions.

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
- Video files are not uploaded through the site. Guests can upload their video to Google Drive, set sharing to "Anyone with the link", and paste the link into the form.
- Supabase stores the messages and video links. This avoids the 50 MB upload limit on Supabase Free projects.
- Keep `SUPABASE_SERVICE_ROLE_KEY` only in Netlify environment variables. Do not put it in frontend HTML or JavaScript.
- The old PHP/MySQL files are still present as an optional fallback for PHP hosting, but Netlify uses `netlify/functions/api.js`.
