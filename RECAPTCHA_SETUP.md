# Google reCAPTCHA v2 Checkbox Setup Guide

## What I've Implemented

I've added Google reCAPTCHA v2 Checkbox to your login form. This protection:
- Shows a visible "I'm not a robot" checkbox that users interact with
- Uses Google's advanced risk analysis to verify user authenticity
- Simple and user-friendly verification method

## Setup Steps

### 1. Get Your reCAPTCHA Keys

1. Go to [Google reCAPTCHA Admin Console](https://www.google.com/recaptcha/admin)
2. Sign in with your Google account (create one if needed)
3. Click **"Create +"** or **"+"** button to create a new site
4. Fill in the form:
   - **Label**: UMERCH (or your preferred name)
   - **reCAPTCHA type**: Select **reCAPTCHA v2** → **"I'm not a robot" Checkbox**
   - **Domains**: Add your domain(s):
     - `localhost` (for local development)
     - `your-domain.com` (for production)
   - Accept the terms and click **Create**

5. You'll receive two keys:
   - **Site Key** (public key)
   - **Secret Key** (private key)

### 2. Add Keys to `.env`

Open `.env` in your project root and update these lines:

```env
RECAPTCHA_SITE_KEY=YOUR_SITE_KEY_HERE
RECAPTCHA_SECRET_KEY=YOUR_SECRET_KEY_HERE
```

Replace `YOUR_SITE_KEY_HERE` and `YOUR_SECRET_KEY_HERE` with the actual keys from Google.

The site key is shared with the React app at runtime by
`app/Http/Middleware/HandleInertiaRequests.php`, so changing it does not require
a rebuild. `VITE_RECAPTCHA_SITE_KEY` is only an optional local fallback.

### 3. Files Modified

The following files have been updated to support reCAPTCHA:

1. **Backend:**
   - `app/Http/Controllers/UsersideControllers/LoginControllers/LoginCont.php` - Added reCAPTCHA verification
   - `config/recaptcha.php` - New config file with reCAPTCHA settings

2. **Frontend:**
   - `resources/js/components/ui/Knowledge.jsx` - Added reCAPTCHA script and token handling

3. **Configuration:**
   - `.env` - Added reCAPTCHA keys
   - `composer.json` - Added `google/recaptcha` package

### 4. How It Works

**Frontend (React):**
- When the page loads, the reCAPTCHA v2 script is loaded from Google
- The checkbox widget appears on the login form
- When the user checks the "I'm not a robot" box, Google validates them
- Upon successful validation, a token is generated and stored
- The token is sent to the backend with login credentials

**Backend (Laravel):**
- The token is validated using the Google reCAPTCHA API
- If validation is successful, login proceeds
- Otherwise, login is rejected with a reCAPTCHA error

### 5. Configuration

The v2 Checkbox doesn't require threshold configuration. It simply validates and returns a pass/fail result.

### 6. Testing

After setting up:
1. Clear your application cache: `php artisan config:clear`
2. Test the login form
3. You should see the "I'm not a robot" checkbox on the login form
4. Click the checkbox and wait for Google to validate
5. Once verified, you can submit the login form

### 7. Troubleshooting

**Issue: Checkbox is not showing**
- Check that your Site Key is correct in `.env`
- Verify the domain in Google Admin Console matches your site
- Clear the config cache: `php artisan config:clear`
- Hard refresh your browser (Ctrl+Shift+R or Cmd+Shift+R)

**Issue: "Please verify that you're not a robot" error**
- Make sure you've clicked and verified the checkbox before submitting
- The checkbox validation may be taking time; wait a moment before submitting

**Issue: reCAPTCHA verification failed on backend**
- Check that your Secret Key is correct in `.env`
- Verify the domain in Google Admin Console matches your site
- Clear the config cache

**Issue: Keys not loading**
- Restart your Laravel server
- Restart your frontend dev server (if using `npm run dev`)
- Check that `.env` changes are saved

**Issue: Still having issues**
- Make sure both keys are properly set in `.env`
- Check browser console for JavaScript errors
- Verify reCAPTCHA keys are for v2 Checkbox type

### 8. Production Deployment

Before going live:
1. Add your production domain to Google reCAPTCHA Admin Console
2. Set `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY` in your host's environment
   (Render: `render.yaml` prompts for both)
3. Test login on production before releasing

### 9. Package Installed

```
google/recaptcha ^1.3.1
```

This package handles the verification of reCAPTCHA tokens on the backend.

---

**Need help?** Check the Google reCAPTCHA documentation: https://developers.google.com/recaptcha/docs/display
