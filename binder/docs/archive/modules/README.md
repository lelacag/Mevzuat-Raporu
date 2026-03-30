# Optional Modules

This directory contains optional modules that can be enabled or disabled independently of the core application.

## Available Modules

### SMS Module (`sms/`)

**Status:** Optional - Disabled by default

**Purpose:** Allows users to post to their profile by sending SMS messages

**When to Enable:**
- You want to offer SMS posting as a feature
- You have budget for SMS costs
- You've chosen an SMS provider

**Quick Start:**
```bash
# Enable the module
echo "SMS_ENABLED=true" >> .env

# Run migration
mysql -u root -p database < modules/sms/migrations/add_sms_support.sql

# Configure provider credentials in modules/sms/config.php
```

**Full Documentation:**
- [SMS Module README](sms/README.md)
- [Installation Guide](sms/INSTALLATION.md)
- [Turkish Providers Guide](../docs/SMS_TURKISH_PROVIDERS.md)

## Creating New Modules

To create a new optional module:

1. **Create module directory:**
   ```bash
   mkdir -p modules/your_module
   ```

2. **Add module structure:**
   ```
   modules/your_module/
   ├── README.md          # Module documentation
   ├── config.php         # Module configuration
   ├── INSTALLATION.md    # Installation guide
   └── migrations/        # Database migrations (if needed)
   ```

3. **Create module config:**
   ```php
   <?php
   // modules/your_module/config.php
   define('YOUR_MODULE_ENABLED', getenv('YOUR_MODULE_ENABLED') === 'true');
   
   if (YOUR_MODULE_ENABLED) {
       // Module-specific configuration
   }
   ```

4. **Load in main config:**
   ```php
   // includes/config.php
   if (file_exists(__DIR__ . '/../modules/your_module/config.php')) {
       require_once __DIR__ . '/../modules/your_module/config.php';
   }
   ```

5. **Use conditionally in application:**
   ```php
   <?php if (defined('YOUR_MODULE_ENABLED') && YOUR_MODULE_ENABLED): ?>
       <!-- Module-specific UI -->
   <?php endif; ?>
   ```

## Module Guidelines

### Best Practices:

1. **Independence:** Modules should not break core functionality when disabled
2. **Configuration:** Use environment variables for sensitive data
3. **Documentation:** Include README and INSTALLATION guides
4. **Migrations:** Keep database changes in separate migration files
5. **Conditional Loading:** Always check if module is enabled before using
6. **Graceful Degradation:** App should work fine with module disabled

### Module Checklist:

- [ ] README.md with overview and features
- [ ] INSTALLATION.md with step-by-step setup
- [ ] config.php with enable/disable flag
- [ ] migrations/ directory (if database changes needed)
- [ ] Conditional loading in main application
- [ ] No hard dependencies on module from core app
- [ ] Documentation of costs/requirements
- [ ] Uninstallation instructions

## Module Status

| Module | Status | Required | Database Changes |
|--------|--------|----------|------------------|
| SMS | Optional | No | Yes (4 columns + 1 table) |

## Disabling All Modules

To run the application with core features only:

```bash
# In .env file
SMS_ENABLED=false
# Add other modules as needed
```

Or rename the modules directory temporarily:
```bash
mv modules modules.disabled
```

Core application will continue to work normally without any optional modules.
