# Mobile App Production Deployment Guide

## 📋 **Overview**

This guide explains how to deploy the Nizami Farms mobile app to production.

---

## 🔧 **Environment Configuration**

### **Development vs Production:**

| Environment | API URL | Usage |
|-------------|---------|-------|
| **Development** | `http://172.20.10.10:8000` | Your laptop's local IP |
| **Production** | `https://your-domain.com` | Your StackCP domain |

---

## 📝 **Step 1: Update `.env` File**

**File:** `NizamiFarmsMobile/.env`

### **Current (Development):**
```
API_URL=http://172.20.10.10:8000
```

### **Change to (Production):**
```
API_URL=https://your-stackcp-domain.com
```

**Replace `your-stackcp-domain.com` with your actual StackCP domain!**

**Example:**
```
API_URL=https://nizamifarms.stackcp.com
```

---

## 🔒 **Step 2: Enable HTTPS**

### **Why HTTPS?**
- Production apps MUST use HTTPS (not HTTP)
- Android blocks HTTP by default for security
- Your StackCP domain already has HTTPS

### **What to Check:**
1. Your StackCP domain has SSL certificate ✅
2. API URL starts with `https://` (not `http://`)
3. Mobile app `.env` uses `https://`

---

## 📱 **Step 3: Build Production APK**

### **Option A: Debug APK (For Testing)**

```bash
cd NizamiFarmsMobile
npx react-native run-android --variant=release
```

**This creates a debug APK that you can install on devices for testing.**

### **Option B: Release APK (For Distribution)**

1. **Generate Signing Key:**
```bash
cd android/app
keytool -genkeypair -v -storetype PKCS12 -keystore nizamifarms-release.keystore -alias nizamifarms -keyalg RSA -keysize 2048 -validity 10000
```

**Enter a password and remember it!**

2. **Configure Gradle:**

Edit `android/gradle.properties`:
```properties
MYAPP_RELEASE_STORE_FILE=nizamifarms-release.keystore
MYAPP_RELEASE_KEY_ALIAS=nizamifarms
MYAPP_RELEASE_STORE_PASSWORD=your-password
MYAPP_RELEASE_KEY_PASSWORD=your-password
```

3. **Build Release APK:**
```bash
cd android
./gradlew assembleRelease
```

**APK Location:**
```
android/app/build/outputs/apk/release/app-release.apk
```

---

## 🚀 **Step 4: Deploy Backend API**

### **Your Laravel Backend (StackCP):**

**What's Already Done:**
- ✅ API routes added (`routes/api.php`)
- ✅ Controllers created (`RiderController.php`)
- ✅ Authentication configured (Laravel Sanctum)

**What You Need to Do:**

1. **Push Code to Production:**
```bash
cd nizamifarms
git add .
git commit -m "Add mobile app API endpoints"
git push origin main
```

2. **Deploy on StackCP:**
- Go to your StackCP dashboard
- Deploy latest code
- Run migrations (if any)

3. **Test API:**
```bash
curl https://your-domain.com/api/auth/me
```

Should return authentication error (which is good - means API is working!)

---

## 📲 **Step 5: Distribute APK to Riders**

### **Option A: Direct Install (Simplest)**

1. Copy APK to riders' phones via:
   - WhatsApp
   - Email
   - USB transfer
   - Cloud storage (Google Drive, Dropbox)

2. On rider's phone:
   - Open APK file
   - Allow "Install from Unknown Sources"
   - Install app

### **Option B: Google Play Store (Professional)**

**Requirements:**
- Google Play Developer Account ($25 one-time fee)
- App Bundle (not APK)
- Privacy Policy
- Screenshots
- App description

**Steps:**
1. Create Play Console account
2. Create new app
3. Upload app bundle
4. Fill app details
5. Submit for review
6. Wait for approval (few days)

**For now, use Option A (Direct Install) for testing!**

---

## 🧪 **Step 6: Testing in Production**

### **Test Checklist:**

1. **Login:**
   - [ ] Rider can log in with credentials
   - [ ] Token is saved correctly
   - [ ] App remembers login

2. **Orders:**
   - [ ] Rider sees assigned orders
   - [ ] Can view order details
   - [ ] Can mark as delivered
   - [ ] GPS location is captured

3. **Ledger:**
   - [ ] Shows correct balance
   - [ ] Can view outstanding invoices
   - [ ] Can settle invoices
   - [ ] Short cash creates expense request

4. **Attendance:**
   - [ ] Can check in/out
   - [ ] Shows monthly summary
   - [ ] Shows all working days
   - [ ] Month selector works

5. **Auto-Refresh:**
   - [ ] Orders refresh on focus
   - [ ] Ledger refreshes on focus
   - [ ] Attendance refreshes on focus

---

## 🔍 **Troubleshooting**

### **Problem: "Network Error" or "Failed to fetch"**

**Solution:**
1. Check `.env` file has correct production URL
2. Ensure URL starts with `https://` (not `http://`)
3. Test API in browser: `https://your-domain.com/api/auth/me`
4. Check StackCP logs for errors

### **Problem: "Unauthorized" or "401 Error"**

**Solution:**
1. Check Laravel Sanctum is configured
2. Verify API routes are in `routes/api.php`
3. Check CORS is enabled for mobile app
4. Test login endpoint first

### **Problem: "App crashes on startup"**

**Solution:**
1. Check `.env` file exists
2. Rebuild app after changing `.env`
3. Check Android logs: `adb logcat`
4. Verify all dependencies are installed

### **Problem: "Can't install APK"**

**Solution:**
1. Enable "Unknown Sources" in Android settings
2. Uninstall old version first
3. Check APK is not corrupted
4. Try different transfer method

---

## 📊 **Monitoring & Maintenance**

### **What to Monitor:**

1. **API Logs (StackCP):**
   - Check Laravel logs for errors
   - Monitor API response times
   - Track failed requests

2. **User Feedback:**
   - Ask riders about issues
   - Track common problems
   - Fix bugs quickly

3. **Database:**
   - Monitor attendance records
   - Check ledger entries
   - Verify order status updates

### **Regular Maintenance:**

1. **Weekly:**
   - Check API logs
   - Review error reports
   - Test critical features

2. **Monthly:**
   - Update dependencies
   - Review performance
   - Plan new features

3. **As Needed:**
   - Fix reported bugs
   - Add requested features
   - Improve UX

---

## 🔄 **Updating the App**

### **When You Make Changes:**

1. **Update Code:**
```bash
cd NizamiFarmsMobile
# Make your changes
```

2. **Test Locally:**
```bash
npx react-native run-android
```

3. **Build New APK:**
```bash
cd android
./gradlew assembleRelease
```

4. **Distribute to Riders:**
- Send new APK
- Ask them to uninstall old version
- Install new version

### **Version Numbering:**

**File:** `android/app/build.gradle`

```gradle
android {
    defaultConfig {
        versionCode 2  // Increment this
        versionName "1.1.0"  // Update this
    }
}
```

**Increment `versionCode` with each release!**

---

## 📱 **App Store Submission (Future)**

### **When You're Ready for Play Store:**

1. **Prepare App Bundle:**
```bash
cd android
./gradlew bundleRelease
```

**Output:** `android/app/build/outputs/bundle/release/app-release.aab`

2. **Create Play Console Account:**
- Go to: https://play.google.com/console
- Pay $25 one-time fee
- Create developer profile

3. **Create App Listing:**
- App name: "Nizami Farms Rider"
- Category: Business
- Upload screenshots
- Write description
- Add privacy policy

4. **Upload App Bundle:**
- Go to "Production" track
- Create new release
- Upload `.aab` file
- Fill release notes
- Submit for review

5. **Wait for Approval:**
- Usually takes 1-3 days
- Check for review feedback
- Fix any issues
- Resubmit if needed

---

## ✅ **Production Checklist**

### **Before Going Live:**

- [ ] Update `.env` with production API URL
- [ ] Test all features with production API
- [ ] Build release APK
- [ ] Test APK on multiple devices
- [ ] Train riders on how to use app
- [ ] Prepare support documentation
- [ ] Set up monitoring/logging
- [ ] Have rollback plan ready

### **After Going Live:**

- [ ] Monitor API logs
- [ ] Check for errors
- [ ] Gather user feedback
- [ ] Fix critical bugs quickly
- [ ] Plan next features
- [ ] Document lessons learned

---

## 🎯 **Summary**

### **Quick Steps to Production:**

1. **Change `.env`:**
   ```
   API_URL=https://your-stackcp-domain.com
   ```

2. **Build APK:**
   ```bash
   cd android
   ./gradlew assembleRelease
   ```

3. **Deploy Backend:**
   ```bash
   git push origin main
   # Deploy on StackCP
   ```

4. **Distribute APK:**
   - Send to riders
   - Install on devices
   - Test everything

5. **Monitor:**
   - Check logs
   - Fix bugs
   - Improve features

---

## 🆘 **Need Help?**

### **Common Issues:**

1. **API not working:** Check StackCP logs
2. **App crashes:** Check Android logs (`adb logcat`)
3. **Can't install APK:** Enable "Unknown Sources"
4. **Network errors:** Verify `.env` URL

### **Resources:**

- Laravel Sanctum Docs: https://laravel.com/docs/sanctum
- React Native Docs: https://reactnative.dev/docs/getting-started
- Android Studio: https://developer.android.com/studio
- StackCP Support: Contact your hosting provider

---

## 🚀 **You're Ready!**

**Everything is set up and working.**
**Just change the `.env` file and build the APK.**
**Your mobile app is production-ready!** 🎉

---

**Questions? Issues? Let me know!**


