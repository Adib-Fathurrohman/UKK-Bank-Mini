# Firebase web config (customer app)

Firebase project **`bank-mini-rumput`** ("Bank Mini"), web app "Bank Mini Customer". The
customer Expo app (Phase 5) initializes the Firebase JS SDK with this config. Only the client
signs in to Firebase; the backend verifies the resulting ID token (see
[CUSTOMER_AUTH.md](CUSTOMER_AUTH.md) and [FRONTEND_HANDOFF.md](FRONTEND_HANDOFF.md) §15.10).

This config is **not secret**: it identifies the project and is shipped inside every client
build. What protects the project is the backend's token verification, the sign-in provider
allow-list and the authorized domains below. The service account key is a different thing
and never goes into the app.

```ts
// customer app: src/lib/firebase.ts
import { initializeApp } from 'firebase/app';

export const firebaseConfig = {
  apiKey: 'AIzaSyDF6DTMnhUQ52aicODJ6c2zNDdOseH_498',
  authDomain: 'bank-mini-rumput.firebaseapp.com',
  projectId: 'bank-mini-rumput',
  storageBucket: 'bank-mini-rumput.firebasestorage.app',
  messagingSenderId: '418347097835',
  appId: '1:418347097835:web:1cd0c891b5d6ca53dfe8ae',
};

export const firebaseApp = initializeApp(firebaseConfig);
```

For Expo, the usual place is `app.config.ts` `extra` or `EXPO_PUBLIC_FIREBASE_*` variables;
the values are the same.

## Google Sign-In

| Item | Value |
|---|---|
| Web OAuth client ID (for native Google Sign-In libraries, `webClientId`) | `418347097835-04u09164m68atrthg908gl5slg2ppfvs.apps.googleusercontent.com` |
| Auth handler (OAuth redirect) | `https://bank-mini-rumput.firebaseapp.com/__/auth/handler` |

Native builds (Android/iOS) need their own app registrations in the Firebase project
(Android: package name plus SHA-1/SHA-256 of the signing keys). Register them when the Expo
app gets its bundle identifiers.

## Push notifications (FCM)

The backend pushes new withdrawal requests to the customer's phones through Firebase Cloud
Messaging (API V1 enabled, verified with a validate-only send). The Expo app gets the FCM
token with `@react-native-firebase/messaging` (development build) and needs:

| Platform | File | How to get it |
|---|---|---|
| Android | `google-services.json` | Project settings → General → Add app → Android, with the final package name |
| iOS | `GoogleService-Info.plist` | Add app → iOS, with the final bundle ID; also upload an APNs key under Cloud Messaging |

These files are not secret. Client guide: [FRONTEND_HANDOFF.md §15.11](FRONTEND_HANDOFF.md#1511-push-notifications-fcm).

## Project settings applied

| Setting | Value |
|---|---|
| Sign-in providers | Email/Password (password required, no email link), Google |
| Backend allow-list | `FIREBASE_ALLOWED_PROVIDERS=password,google.com` |
| Authorized domains | `localhost`, `bank.rumput.tech`, `app.rumput.tech` (plus the default `bank-mini-rumput.firebaseapp.com`, `bank-mini-rumput.web.app`) |
| Email template language | Indonesian (`id`), sender name "Bank Mini" |
| Email enumeration protection | enabled |
| Public app name (OAuth consent) | Bank Mini |

## Backend

| Variable | Value |
|---|---|
| `FIREBASE_PROJECT_ID` | `bank-mini-rumput` |
| `FIREBASE_CREDENTIALS` | `storage/app/private/firebase-credentials.json` (service account `firebase-adminsdk-fbsvc@bank-mini-rumput.iam.gserviceaccount.com`; git-ignored, never in the app) |
