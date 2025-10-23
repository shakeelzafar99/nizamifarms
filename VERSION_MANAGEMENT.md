# Version Management Guide

## 📋 **Current Version: 1.0.0**

This document explains how to manage app versions for the Nizami Farms mobile app.

---

## 🔢 **Version Numbers Explained**

### **Format: MAJOR.MINOR.PATCH**

Example: **1.0.0**

- **MAJOR (1):** Breaking changes, major redesigns
- **MINOR (0):** New features, non-breaking changes
- **PATCH (0):** Bug fixes, minor improvements

---

## 📝 **When to Update Versions**

### **MAJOR Version (1.x.x → 2.x.x)**
**When:**
- Complete app redesign
- Breaking API changes
- Major feature overhaul
- Requires users to update

**Example:**
- 1.0.0 → 2.0.0 (Complete UI redesign)

### **MINOR Version (x.0.x → x.1.x)**
**When:**
- New features added
- New screens added
- Significant improvements
- Non-breaking changes

**Example:**
- 1.0.0 → 1.1.0 (Added Requests screen)
- 1.1.0 → 1.2.0 (Added notifications)

### **PATCH Version (x.x.0 → x.x.1)**
**When:**
- Bug fixes
- Minor UI tweaks
- Performance improvements
- Small changes

**Example:**
- 1.0.0 → 1.0.1 (Fixed login bug)
- 1.0.1 → 1.0.2 (Improved loading speed)

---

## 🔧 **How to Update Version**

### **Step 1: Update package.json**

**File:** `NizamiFarmsMobile/package.json`

```json
{
  "name": "NizamiFarmsMobile",
  "version": "1.1.0",  // ← Update this
  "private": true,
  ...
}
```

### **Step 2: Update build.gradle**

**File:** `NizamiFarmsMobile/android/app/build.gradle`

```gradle
defaultConfig {
    applicationId "com.nizamifarmsmobile"
    minSdkVersion rootProject.ext.minSdkVersion
    targetSdkVersion rootProject.ext.targetSdkVersion
    versionCode 2          // ← Increment by 1
    versionName "1.1.0"    // ← Match package.json
    ...
}
```

**IMPORTANT:**
- `versionCode` MUST increment by 1 each release (1, 2, 3, 4...)
- `versionName` should match package.json version
- Google Play uses `versionCode` to determine which version is newer

### **Step 3: Update LoginScreen (Automatic)**

The login screen automatically reads version from `package.json`:

```javascript
import packageJson from '../../package.json';

<Text style={styles.version}>Version {packageJson.version}</Text>
```

**No manual update needed!**

---

## 📊 **Version History**

### **Version 1.0.0** (October 23, 2025)
**Initial Release**

**Features:**
- ✅ Login with authentication
- ✅ Orders list (open, delivered, all)
- ✅ Order details with mark as delivered
- ✅ GPS tracking on delivery
- ✅ Ledger with balance and transactions
- ✅ Invoice settlement (full & partial)
- ✅ Short cash with expense categories
- ✅ Attendance (check-in/out, monthly view)
- ✅ Auto-refresh on screen focus

**Changes:**
- versionCode: 1
- versionName: "1.0.0"

---

### **Version 1.1.0** (October 23, 2025)
**Requests Feature**

**New Features:**
- ✅ Create requests (petrol, salary advance, leave)
- ✅ View pending requests
- ✅ View approved requests
- ✅ Request history
- ✅ Filter by status (all, pending, approved)
- ✅ Auto-refresh on screen focus
- ✅ Category-specific forms (expense, salary advance, leave)

**Changes:**
- versionCode: 2
- versionName: "1.1.0"

---

## 🎯 **Version Update Checklist**

Before releasing a new version:

- [ ] Update `package.json` version
- [ ] Update `build.gradle` versionCode (increment by 1)
- [ ] Update `build.gradle` versionName (match package.json)
- [ ] Test all features
- [ ] Build release APK
- [ ] Test APK on device
- [ ] Document changes in this file
- [ ] Commit changes to git
- [ ] Tag release in git: `git tag v1.1.0`

---

## 📱 **Git Tagging**

### **Create Version Tag:**

```bash
git tag -a v1.0.0 -m "Initial release - Orders, Ledger, Attendance"
git push origin v1.0.0
```

### **List All Tags:**

```bash
git tag
```

### **View Tag Details:**

```bash
git show v1.0.0
```

---

## 🔄 **Version Update Example**

### **Scenario: Adding Requests Screen**

**Current Version:** 1.0.0
**New Version:** 1.1.0 (MINOR - new feature)

**Steps:**

1. **Update package.json:**
```json
"version": "1.1.0"
```

2. **Update build.gradle:**
```gradle
versionCode 2
versionName "1.1.0"
```

3. **Build APK:**
```bash
cd android
./gradlew assembleRelease
```

4. **Commit & Tag:**
```bash
git add .
git commit -m "v1.1.0 - Added Requests screen"
git tag -a v1.1.0 -m "Added Requests screen for petrol, salary advance, leave"
git push origin main
git push origin v1.1.0
```

---

## 📋 **Version Naming Convention**

### **Development Builds:**
- Use `-dev` suffix
- Example: `1.1.0-dev`
- For internal testing only

### **Beta Builds:**
- Use `-beta` suffix
- Example: `1.1.0-beta`
- For limited testing

### **Release Builds:**
- No suffix
- Example: `1.1.0`
- For production use

---

## 🚀 **Quick Reference**

### **Current Version:**
```
Version: 1.1.0
Version Code: 2
Release Date: October 23, 2025
```

### **Next Version (Planned):**
```
Version: 1.2.0
Version Code: 3
Release Date: TBD
Features: TBD
```

### **Files to Update:**
1. `package.json` → version
2. `android/app/build.gradle` → versionCode & versionName
3. `VERSION_MANAGEMENT.md` → version history (this file)

---

## ✅ **Summary**

**To update version:**
1. Decide version number (MAJOR.MINOR.PATCH)
2. Update `package.json`
3. Update `build.gradle` (both versionCode and versionName)
4. Build and test
5. Commit and tag
6. Document in this file

**Version displays automatically on login screen!**

---

**Current Version: 1.1.0** 🎉

