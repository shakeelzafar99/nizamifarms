# Mobile Authentication Enhancements

## Overview
This document describes two key enhancements to the mobile app authentication system:
1. **Password Storage** - API now returns the password so mobile apps can store it securely for auto-login
2. **Default View Logic** - API determines the default starting view (Store or Rider) based on user permissions

## Changes Made

### 1. Password Storage Enhancement

#### Backend Changes
The authentication API (`POST /api/auth/authenticate`) now returns the user's password in the response when authentication is successful.

**Modified File:** `app/Http/Controllers/AuthController.php`

**Changes:**
- `authenticate()` method now passes the password to `respondWithToken()`
- `respondWithToken()` method accepts an optional `$password` parameter
- Password is included in the JSON response when provided

#### API Response
```json
{
  "isError": false,
  "access_token": "1|abc123...",
  "authToken": "1|abc123...",
  "refreshToken": "1|abc123...",
  "tokenType": "bearer",
  "expires_at": "2024-11-02T15:30:00.000000Z",
  "user": {
    "id": 45,
    "fullname": "Ali Khan",
    "email": "ali@example.com",
    "user_type": "rider"
  },
  "password": "user_entered_password",
  "mobile_permissions": ["access_store_mode", "view_open_orders", ...],
  "has_store_access": true,
  "default_view": "store"
}
```

#### Mobile App Implementation

**Security Best Practices:**

1. **Use Secure Storage**
   - iOS: Use Keychain Services
   - Android: Use EncryptedSharedPreferences or Android Keystore

2. **Never Store in Plain Text**
   - Don't use AsyncStorage, SharedPreferences, or localStorage
   - Always use platform-specific secure storage

**Example Implementation (React Native):**

```javascript
import * as SecureStore from 'expo-secure-store';
// or
import { Keychain } from 'react-native-keychain';

// After successful login
const handleLoginSuccess = async (response) => {
  try {
    // Store credentials securely
    await SecureStore.setItemAsync('user_email', response.user.email);
    await SecureStore.setItemAsync('user_password', response.password);
    await SecureStore.setItemAsync('auth_token', response.access_token);
    
    // Store user data and permissions
    await AsyncStorage.setItem('user_data', JSON.stringify(response.user));
    await AsyncStorage.setItem('mobile_permissions', JSON.stringify(response.mobile_permissions));
    await AsyncStorage.setItem('default_view', response.default_view);
    
    // Navigate to app
    navigateToApp(response.default_view);
  } catch (error) {
    console.error('Failed to store credentials:', error);
  }
};

// Auto-login on app start
const attemptAutoLogin = async () => {
  try {
    const email = await SecureStore.getItemAsync('user_email');
    const password = await SecureStore.getItemAsync('user_password');
    
    if (email && password) {
      // Attempt login
      const response = await fetch('https://yourdomain.com/api/auth/authenticate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      });
      
      const data = await response.json();
      
      if (!data.isError) {
        await handleLoginSuccess(data);
      } else {
        // Invalid credentials - clear stored data
        await clearStoredCredentials();
        navigateToLogin();
      }
    } else {
      navigateToLogin();
    }
  } catch (error) {
    console.error('Auto-login failed:', error);
    navigateToLogin();
  }
};

// Clear credentials on logout
const clearStoredCredentials = async () => {
  await SecureStore.deleteItemAsync('user_email');
  await SecureStore.deleteItemAsync('user_password');
  await SecureStore.deleteItemAsync('auth_token');
  await AsyncStorage.removeItem('user_data');
  await AsyncStorage.removeItem('mobile_permissions');
  await AsyncStorage.removeItem('default_view');
};
```

**Using React Native Keychain:**

```javascript
import * as Keychain from 'react-native-keychain';

// Store credentials
await Keychain.setGenericPassword(email, password, {
  service: 'com.nizamifarms.app',
  accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED
});

// Retrieve credentials
const credentials = await Keychain.getGenericPassword({
  service: 'com.nizamifarms.app'
});

if (credentials) {
  const { username: email, password } = credentials;
  // Attempt auto-login
}

// Clear credentials
await Keychain.resetGenericPassword({
  service: 'com.nizamifarms.app'
});
```

### 2. Default View Logic

#### Backend Changes
The authentication API now determines the default starting view based on user permissions.

**Logic:**
- If user has `access_store_mode` permission → `default_view: "store"`
- Otherwise → `default_view: "rider"`

**Modified File:** `app/Http/Controllers/AuthController.php`

**Changes:**
- `respondWithToken()` method now loads user roles and mobile permissions
- Checks for `access_store_mode` permission
- Returns `mobile_permissions`, `has_store_access`, and `default_view` in response

#### API Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `mobile_permissions` | array | All mobile permissions for the user |
| `has_store_access` | boolean | Quick check if user has store access |
| `default_view` | string | Default starting view: "store" or "rider" |

#### Mobile App Implementation

**Navigation Logic:**

```javascript
// After successful login
const navigateAfterLogin = (response) => {
  // Store permissions and default view
  AsyncStorage.setItem('mobile_permissions', JSON.stringify(response.mobile_permissions));
  AsyncStorage.setItem('has_store_access', response.has_store_access.toString());
  AsyncStorage.setItem('default_view', response.default_view);
  
  // Navigate based on default view
  if (response.default_view === 'store') {
    navigation.navigate('StoreView');
  } else {
    navigation.navigate('RiderView');
  }
};

// On app start (after auto-login)
const navigateToDefaultView = async () => {
  const defaultView = await AsyncStorage.getItem('default_view');
  
  if (defaultView === 'store') {
    navigation.navigate('StoreView');
  } else {
    navigation.navigate('RiderView');
  }
};
```

**Tab Bar Configuration:**

```javascript
// Show/hide tabs based on permissions
const TabNavigator = () => {
  const [hasStoreAccess, setHasStoreAccess] = useState(false);
  
  useEffect(() => {
    AsyncStorage.getItem('has_store_access').then(value => {
      setHasStoreAccess(value === 'true');
    });
  }, []);
  
  return (
    <Tab.Navigator>
      <Tab.Screen name="Rider" component={RiderScreen} />
      {hasStoreAccess && (
        <Tab.Screen name="Store" component={StoreScreen} />
      )}
      <Tab.Screen name="Profile" component={ProfileScreen} />
    </Tab.Navigator>
  );
};
```

**Initial Screen Logic:**

```javascript
// In your root navigator
const RootNavigator = () => {
  const [isLoading, setIsLoading] = useState(true);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [defaultView, setDefaultView] = useState('rider');
  
  useEffect(() => {
    checkAuthentication();
  }, []);
  
  const checkAuthentication = async () => {
    try {
      const token = await SecureStore.getItemAsync('auth_token');
      const storedDefaultView = await AsyncStorage.getItem('default_view');
      
      if (token) {
        // Verify token is still valid
        const response = await fetch('https://yourdomain.com/api/auth/me', {
          headers: { 'Authorization': `Bearer ${token}` }
        });
        
        if (response.ok) {
          setIsAuthenticated(true);
          setDefaultView(storedDefaultView || 'rider');
        } else {
          // Token invalid - attempt auto-login
          await attemptAutoLogin();
        }
      } else {
        // No token - attempt auto-login
        await attemptAutoLogin();
      }
    } catch (error) {
      console.error('Auth check failed:', error);
    } finally {
      setIsLoading(false);
    }
  };
  
  if (isLoading) {
    return <SplashScreen />;
  }
  
  return (
    <Stack.Navigator>
      {!isAuthenticated ? (
        <Stack.Screen name="Login" component={LoginScreen} />
      ) : (
        <>
          <Stack.Screen 
            name="Main" 
            component={MainTabNavigator}
            initialParams={{ defaultView }}
          />
        </>
      )}
    </Stack.Navigator>
  );
};
```

## Mobile Permissions

The following mobile permissions are available:

| Permission Code | Description |
|----------------|-------------|
| `access_store_mode` | Access to store view and features |
| `view_open_orders` | View all open orders in store |
| `assign_riders` | Assign riders to orders |
| `change_order_status` | Change order status |
| `enter_packet_info` | Enter/edit packet information |
| `view_open_quantities` | View open order quantities report |
| `manage_store_expenses` | Manage store expenses |
| `view_store_reports` | View store reports |

## Security Considerations

### Password Storage
1. **Always use secure storage** - Never store passwords in plain text
2. **Platform-specific encryption** - Use Keychain (iOS) or Keystore (Android)
3. **Clear on logout** - Always clear stored credentials when user logs out
4. **Handle token expiration** - Re-authenticate if token expires
5. **Biometric option** - Consider adding biometric authentication as alternative

### Token Management
1. **Store tokens securely** - Use same secure storage as passwords
2. **Refresh tokens** - Implement token refresh logic for long sessions
3. **Validate on app start** - Always verify token is still valid
4. **Handle revocation** - Clear local data if token is revoked

### Permission Checks
1. **Client-side validation** - Hide UI elements user doesn't have access to
2. **Server-side enforcement** - Backend always validates permissions
3. **Sync permissions** - Refresh permissions periodically or on app start
4. **Handle permission changes** - Update UI if admin changes user permissions

## Testing

### Test Scenarios

#### Password Storage
1. ✅ Login successfully → Password stored securely
2. ✅ Close app → Reopen → Auto-login works
3. ✅ Logout → Password cleared from storage
4. ✅ Wrong password → Stored credentials cleared
5. ✅ Token expired → Auto-login re-authenticates

#### Default View
1. ✅ User with store access → Opens to store view
2. ✅ User without store access → Opens to rider view
3. ✅ Store user can switch to rider view manually
4. ✅ Rider user cannot access store view
5. ✅ Permission change → Default view updates on next login

#### Permissions
1. ✅ Store access permission → Store tab visible
2. ✅ No store access → Store tab hidden
3. ✅ Permissions loaded on login
4. ✅ Permissions persist across app restarts
5. ✅ Permission check before API calls

## API Endpoints

### Authentication
**POST** `/api/auth/authenticate`

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "isError": false,
  "access_token": "1|abc123...",
  "authToken": "1|abc123...",
  "refreshToken": "1|abc123...",
  "tokenType": "bearer",
  "expires_at": "2024-11-02T15:30:00.000000Z",
  "user": {
    "id": 45,
    "fullname": "Ali Khan",
    "email": "ali@example.com",
    "user_type": "rider"
  },
  "password": "password123",
  "mobile_permissions": [
    "access_store_mode",
    "view_open_orders",
    "assign_riders"
  ],
  "has_store_access": true,
  "default_view": "store"
}
```

### Get Current User
**GET** `/api/auth/me`

**Headers:**
```
Authorization: Bearer {token}
```

### Get Mobile Permissions
**GET** `/api/rider/permissions`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "permissions": [
    "access_store_mode",
    "view_open_orders",
    "assign_riders"
  ],
  "has_store_mode": true
}
```

## Migration Guide

### For Existing Mobile Apps

1. **Update Login Flow**
   - Capture password from login response
   - Store password securely using Keychain/Keystore
   - Store `default_view` from response

2. **Add Auto-Login**
   - Check for stored credentials on app start
   - Attempt authentication if credentials exist
   - Navigate to default view on success

3. **Update Navigation**
   - Read `default_view` from login response
   - Navigate to store or rider view accordingly
   - Show/hide store tab based on `has_store_access`

4. **Update Logout**
   - Clear stored password and credentials
   - Revoke token on server
   - Navigate to login screen

### Backward Compatibility

All changes are **backward compatible**:
- Existing mobile apps will continue to work
- New fields are added, no existing fields removed
- Apps can ignore new fields until they implement the features
- Web authentication flow unchanged

## Files Modified

1. **app/Http/Controllers/AuthController.php**
   - `authenticate()` method: Pass password to `respondWithToken()`
   - `respondWithToken()` method: 
     - Accept optional `$password` parameter
     - Load user roles and mobile permissions
     - Calculate `has_store_access` and `default_view`
     - Include password, permissions, and default view in response

## Notes

- Password is only returned on successful authentication
- Password is the plain text password user entered (not hashed)
- Mobile app is responsible for secure storage
- Default view logic is server-side to ensure consistency
- Permissions are loaded fresh on each login
- Web authentication flow is unchanged

## Support

For issues or questions:
- Backend: Check Laravel logs at `storage/logs/laravel.log`
- Mobile: Check console logs for API response
- Security concerns: Contact security team
- Contact: support@nizamifarms.com

