plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
    // google-services.json is NOT in the repository — it carries the Firebase
    // project's own identifiers and is supplied per environment. Without it
    // this plugin fails the build at :app:processDebugGoogleServices.
    id("com.google.gms.google-services")
}

android {
    namespace = "ph.gov.echague.serbis"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "ph.gov.echague.serbis"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    // The integration_test plugin (Flutter SDK) pulls espresso-core via a
    // "3.2+" constraint that resolves to the literal 3.2.0 release, which
    // pairs with espresso-idling-resource:3.2.0 — two artifacts declaring the
    // same manifest namespace, which AGP 9's stricter merge check rejects
    // outright (":app:processDebugMainManifest" fails, since integration_test
    // lands this on the main debug classpath, not just androidText's).
    // Fixed upstream past 3.2.0; pinning here wins Gradle's default
    // highest-version resolution over the transitive request.
    implementation("androidx.test.espresso:espresso-core:3.5.1")
}
