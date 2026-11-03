# Firebase project (push notifications)

Firebase project **`bank-mini-rumput`** ("Bank Mini"). On this branch
(`feature/nis-pin-login`) customers sign in with NIS + PIN, and Firebase is used **only for
push notifications** through Firebase Cloud Messaging (FCM). See
[CUSTOMER_AUTH.md](CUSTOMER_AUTH.md) §4 and [FRONTEND_NIS_PIN_FCM.md](FRONTEND_NIS_PIN_FCM.md)
§2.6.

## Customer app (Expo, React Native Firebase)

The app uses `@react-native-firebase/app` + `@react-native-firebase/messaging`, configured
by the platform files, not by a JS config object:

| Platform | File | How to get it |
|---|---|---|
| Android | `google-services.json` | Firebase console → Project settings → General → Add app → Android, with the final package name |
| iOS | `GoogleService-Info.plist` | Same, Add app → iOS, with the final bundle ID; also upload an APNs key under Cloud Messaging |

These files identify the project and are **not secret**. The service account key is a
different thing and never goes into the app.

| Item | Value |
|---|---|
| Project ID | `bank-mini-rumput` |
| Project number (FCM sender ID) | `418347097835` |
| Firebase Cloud Messaging API (V1) | Enabled (verified with a validate-only send from the backend) |

A web app ("Bank Mini Customer", app ID `1:418347097835:web:1cd0c891b5d6ca53dfe8ae`) is also
registered. It is only needed for an Expo **web** build. Web push would also need a VAPID
key and is not used.

## Firebase Authentication (unused on this branch)

The project still has Email/Password and Google sign-in, authorized domains (`localhost`,
`bank.rumput.tech`, `app.rumput.tech`) and Indonesian email templates configured from the
Firebase-login version on `main`. They are not used here; leave them or disable them in
the console.

## Backend

| Variable | Value |
|---|---|
| `FIREBASE_PROJECT_ID` | `bank-mini-rumput` |
| `FIREBASE_CREDENTIALS` | `storage/app/private/firebase-credentials.json` (service account `firebase-adminsdk-fbsvc@bank-mini-rumput.iam.gserviceaccount.com`; git-ignored, never in the app). Without it the backend works but sends no pushes |
