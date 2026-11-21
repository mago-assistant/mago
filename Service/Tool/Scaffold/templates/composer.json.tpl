{
    "name": "{{package}}",
    "description": "Mago Assistant tool {{tool_name}}",
    "type": "magento2-module",
    "version": "1.0.0",
    "license": "proprietary",
    "require": {
        "php": ">=8.2",
        "mago-assistant/magento2-mago": "{{mago_constraint}}"
    },
    "autoload": {
        "files": [
            "registration.php"
        ],
        "psr-4": {
            "{{Vendor}}\\{{Module}}\\": ""
        }
    }
}
