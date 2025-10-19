# 📋 Mobile App Installation Checklist

## ✅ Your Tasks (Before We Start Development)

### **1. Install Node.js**
- [ ] Download from: https://nodejs.org/
- [ ] Install **LTS version** (v18 or higher)
- [ ] **Verify installation:**
  ```powershell
  node --version
  # Should show: v18.x.x or higher
  
  npm --version
  # Should show: 9.x.x or higher
  ```

---

### **2. Install Java JDK 17**
- [ ] Download from: https://adoptium.net/ (recommended)
- [ ] Or: https://www.oracle.com/java/technologies/downloads/#java17
- [ ] Install JDK 17
- [ ] **Verify installation:**
  ```powershell
  java -version
  # Should show: java version "17.x.x"
  ```
- [ ] **Set JAVA_HOME environment variable:**
  - Right-click "This PC" → Properties
  - Advanced system settings → Environment Variables
  - Add new System Variable:
    - Name: `JAVA_HOME`
    - Value: `C:\Program Files\Eclipse Adoptium\jdk-17.x.x` (or your install path)

---

### **3. Install Android Studio**
- [ ] Download from: https://developer.android.com/studio
- [ ] Run installer
- [ ] During installation, make sure these are checked:
  - ✅ Android SDK
  - ✅ Android SDK Platform
  - ✅ Android Virtual Device
  - ✅ Performance (Intel HAXM)

#### **After Installing Android Studio:**

- [ ] **Open Android Studio**
- [ ] Click "More Actions" → "SDK Manager"
- [ ] In "SDK Platforms" tab, install:
  - ✅ Android 13.0 (Tiramisu) API Level 33 or higher
- [ ] In "SDK Tools" tab, ensure these are installed:
  - ✅ Android SDK Build-Tools
  - ✅ Android SDK Command-line Tools
  - ✅ Android Emulator
  - ✅ Android SDK Platform-Tools
  - ✅ Google Play services

#### **Set Android Environment Variables:**

- [ ] Open System Environment Variables (same as JAVA_HOME step)
- [ ] Add/Update these System Variables:
  
  **ANDROID_HOME:**
  - Name: `ANDROID_HOME`
  - Value: `C:\Users\<YourUsername>\AppData\Local\Android\Sdk`
  
  **PATH (add these):**
  - `%ANDROID_HOME%\platform-tools`
  - `%ANDROID_HOME%\emulator`
  - `%ANDROID_HOME%\tools`
  - `%ANDROID_HOME%\tools\bin`

- [ ] **Verify Android SDK:**
  ```powershell
  # Close and reopen PowerShell after setting environment variables
  adb --version
  # Should show: Android Debug Bridge version x.x.x
  ```

#### **Create Android Virtual Device (Emulator):**

- [ ] In Android Studio: Tools → Device Manager (or AVD Manager)
- [ ] Click "Create Device"
- [ ] Choose: **Pixel 5** (or any recent device)
- [ ] Choose System Image: **Android 13 (API 33)** - Download if needed
- [ ] Click Next → Finish
- [ ] **Test the emulator:** Click the ▶️ play button to start it
- [ ] Emulator should boot up (may take 2-3 minutes first time)

---

## 📝 Final Verification

Once everything is installed, run these commands in **PowerShell** (close and reopen after setting environment variables):

```powershell
# Check Node.js
node --version
npm --version

# Check Java
java -version

# Check Android SDK
adb --version

# Check environment variables
echo $env:JAVA_HOME
echo $env:ANDROID_HOME
```

**Copy and paste the output here for Cursor to verify:**

```
[Paste your output here when done]
```

---

## 🚀 When You're Done

Reply with:
- ✅ "All installed and verified"
- 📋 Paste the output from the verification commands above

**Then Cursor will:**
1. Create the mobile app project
2. Set up React Native
3. Build the first working version
4. Show you how to run it on the emulator

---

## 🆘 Common Issues

### **Node.js Not Found**
- Close and reopen PowerShell
- Make sure you downloaded the installer (not just binaries)

### **Java Version Wrong**
- Make sure you installed JDK 17 (not JRE, not JDK 8/11/21)
- Check JAVA_HOME points to JDK folder

### **adb Not Found**
- Close and reopen PowerShell after setting environment variables
- Verify ANDROID_HOME path is correct
- Check PATH includes `%ANDROID_HOME%\platform-tools`

### **Emulator Won't Start**
- Enable Virtualization in BIOS (Intel VT-x or AMD-V)
- Windows: Enable Hyper-V or Windows Hypervisor Platform
  - Control Panel → Programs → Turn Windows features on/off
  - Check "Windows Hypervisor Platform"

---

## 💡 Tips

- **Installation will take 1-2 hours** (Android Studio is large ~4GB)
- **Have good internet** (many downloads)
- **Restart PowerShell** after setting environment variables
- **Restart your laptop** if emulator has issues

---

**Take your time with the installation. Once done, development will be smooth! 🎉**

