# User Management API Module

This Drupal custom module provides a collection of REST API endpoints for user registration, profiles, email verification, password management, auditing events, and comprehensive tests. It is fully secured using the **Simple OAuth** module.

## Features

- **Authentication via OAuth2:** Secure login, token validation, and logout.
- **User Management:** View profile, update profile details, and update password.
- **Events & Audit Logging:** Dispatches Symfony events for all major user actions (`UserRegisteredEvent`, `UserVerifiedEvent`, `PasswordChangedEvent`, `UserUpdatedEvent`) and logs them automatically via standard watchdog logs.
- **Error Handling:** Centralized exception handling using `ApiException` and standard JSON error response structures.

## Simple OAuth Setup

To access the secured endpoints, you must configure Simple OAuth on your Drupal site:

1. **Generate Keys:**
   Run the following commands in a directory outside your web root (or inside a secure folder) to generate the public/private key pairs:
   ```bash
   openssl genrsa -out private.key 2048
   openssl rsa -in private.key -pubout -out public.key
   chmod 600 private.key public.key
   ```
2. **Configure Simple OAuth Keys:**
   Go to `/admin/config/services/simple_oauth` in your Drupal admin panel and specify the absolute paths to the `private.key` and `public.key` files.
3. **Create an OAuth Client:**
   Go to `/admin/config/services/consumer` and add a new Consumer (OAuth Client). Note the generated Client ID (UUID) and set a Client Secret if using one.

## Obtaining access tokens

### 1. Register User (Requires Client Credentials / Access Token)
If registration is secured via Client Credentials, first request a client-level token:
- **URL:** `POST /oauth/token`
- **Body parameters (form-data / x-www-form-urlencoded):**
  - `grant_type`: `client_credentials`
  - `client_id`: `<CLIENT_UUID>`
  - `client_secret`: `<CLIENT_SECRET>`

### 2. User Login (Password Grant Type)
To authenticate a user and get an access token:
- **URL:** `POST /oauth/token`
- **Body parameters (form-data / x-www-form-urlencoded):**
  - `grant_type`: `password`
  - `client_id`: `<CLIENT_UUID>`
  - `client_secret`: `<CLIENT_SECRET>`
  - `username`: `<USER_EMAIL_OR_USERNAME>`
  - `password`: `<USER_PASSWORD>`

This will return a JSON payload with `access_token`, `token_type` (Bearer), and `expires_in`.

---

## Endpoints

All endpoints (except `verify-email`) require the `Authorization: Bearer <access_token>` header.

### Authentication
- `POST /api/auth/register` - Create a new user account.
- `POST /api/auth/logout` - Invalidate current session (client-side token clearance or standard logout).
- `GET /api/auth/verify-email` - Verifies user email via token (received in verification email link).

### Profile Operations
- `GET /api/user/profile` - Fetch authenticated user details.
- `PUT /api/user/profile` - Update authenticated user details (first name, last name, bio).
- `POST /api/user/password` - Change user password (requires current password).

---

## Services Provided

- `user_management_api.auth_manager`: Core auth handlers.
- `user_management_api.user_manager`: User profile and DB handlers.
- `user_management_api.email_verification_manager`: Email generation, validation token logic, verification endpoints.
- `user_management_api.response_manager`: Standard JSON formatting.

## Configuration

Default configurations can be adjusted under:
- `user_management_api.settings`
  - `token_expiry`: Token expiry duration in seconds.
  - `require_email_verification`: Set to `true` if new registrations must verify email before logging in.
