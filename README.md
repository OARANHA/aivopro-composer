# oaranha/aivopro-composer

**AiVoPro Composer Plugin** - Enhanced custom installer for plugins/themes with advanced asset management.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](composer.json)

## 🚀 Installation

```bash
composer require oaranha/aivopro-composer
```

## ✨ Features

- 🎯 Custom installer for `aivopro-plugin` and `aivopro-theme` package types
- 📦 Automatic copying of public assets to the webroot
- 🧹 Automatic cleanup of public assets on uninstall
- 🌐 Support for glob patterns
- 🎨 Flexible target path resolution
- 💾 File mapping storage for safe removal

## 📋 Public Files Configuration

AiVoPro plugins can define public files/directories that should be copied to the webroot during installation. This is configured in the package's `composer.json` under `extra.public`.

### Basic Syntax

```json
{
  "extra": {
    "public": [
      "path/to/file.js",
      "path/to/directory",
      {
        "source": "source/path",
        "target": "target/path"
      }
    ]
  }
}
```

### Target Path Resolution

The target path follows **"Model A"** where **target is ALWAYS the final destination path**:

| Target Format    | Description                     | Result                               |
| ---------------- | ------------------------------- | ------------------------------------ |
| `"file.js"`      | Package dir + target            | `public/e/{vendor}/{pkg}/file.js`    |
| `"/file.js"`     | Webroot + target                | `public/file.js`                     |
| `"."`            | Package dir + source basename   | `public/e/{vendor}/{pkg}/{basename}` |
| `"/."` or `"/"`  | Webroot + source basename       | `public/{basename}`                  |
| `null` (omitted) | Package dir + source basename   | `public/e/{vendor}/{pkg}/{basename}` |
| `"dir/*"`        | Glob: contents copied to target | Contents copied to target directory  |

## 📚 Usage Examples

### 1️⃣ Legacy String Format (preserves full path)

```json
{
  "extra": {
    "public": [
      "widget/dist/index.html",
      "widget/dist/styles.css"
    ]
  }
}
```

**Result:**
- `widget/dist/index.html` → `public/e/{vendor}/{pkg}/widget/dist/index.html`
- `widget/dist/styles.css` → `public/e/{vendor}/{pkg}/widget/dist/styles.css`

### 2️⃣ Copy to Package Directory (no leading `/`)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist/sdk.js",
        "target": "sdk.js"
      },
      {
        "source": "assets",
        "target": "static"
      }
    ]
  }
}
```

**Result:**
- `widget/dist/sdk.js` → `public/e/{vendor}/{pkg}/sdk.js`
- `assets/` → `public/e/{vendor}/{pkg}/static/`

### 3️⃣ Copy to Webroot (leading `/`)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist/sdk.js",
        "target": "/sdk.js"
      },
      {
        "source": "assets",
        "target": "/static/assets"
      }
    ]
  }
}
```

**Result:**
- `widget/dist/sdk.js` → `public/sdk.js`
- `assets/` → `public/static/assets/`

### 4️⃣ Using Basename Shortcuts (`.` and `/.`)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist",
        "target": "."
      },
      {
        "source": "widget/dist",
        "target": "/."
      },
      {
        "source": "widget/dist"
      }
    ]
  }
}
```

**Result:**
- `"."` → `public/e/{vendor}/{pkg}/dist/` (package dir + basename)
- `"/."` → `public/dist/` (webroot + basename)
- No target → `public/e/{vendor}/{pkg}/dist/` (same as `"."`)  

### 5️⃣ Glob Patterns (copy contents)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist/*",
        "target": "/assets"
      }
    ]
  }
}
```

**Result:**
- Contents of `widget/dist/` (e.g., `index.html`, `css/`, `js/`) → `public/assets/`
- Files become `public/assets/index.html`, `public/assets/css/...`, etc.

## ⚙️ Environment Variables

| Variable     | Description         | Default  |
| ------------ | ------------------- | -------- |
| `PUBLIC_DIR` | Custom webroot path | `public` |

**Example:**
```bash
export PUBLIC_DIR="web"
composer install
```

## 📦 Package Types

This plugin handles the following package types:

- `aivopro-plugin` - Installed to `public/content/plugins/{vendor}/{package}`
- `aivopro-theme` - Installed to `public/content/plugins/{vendor}/{package}`

You can customize the installation path in your root `composer.json`:

```json
{
  "extra": {
    "paths": {
      "extensions": "custom/path/to/extensions/"
    }
  }
}
```

## 🔧 Development

### Quality Assurance

```bash
# Run all checks
composer analyse

# Individual tools
composer phpstan      # Static analysis
composer phpcs        # Code style check
composer phpmd        # Mess detection
composer unit-test    # Unit tests

# Fix code style issues
composer fix
```

### Testing

```bash
# Run tests
composer unit-test

# Generate coverage report
composer code-coverage
```

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 👤 Author

**A.Aranha**
- Email: aranha@ulbra.edu.br
- GitHub: [@OARANHA](https://github.com/OARANHA)

---

Made with ❤️ for the AiVoPro ecosystem
