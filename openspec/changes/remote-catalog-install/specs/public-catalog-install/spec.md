# Spec: public-catalog-install

## ADDED Requirements

### Requirement: Public catalog install provider

When `system_updater` is loaded, it MUST register a `PluginInstallProvider` that can install plugins listed in the public catalog.

#### Scenario: Download missing public plugin

- **GIVEN** `catalogo_core` is in `custom_plugins.json`
- **AND** `plugins/catalogo_core/` does not exist
- **WHEN** the orchestrator calls `installIfAvailable('catalogo_core')`
- **THEN** the plugin is downloaded and extracted to `plugins/catalogo_core/`
- **AND** the method returns true

#### Scenario: Reject non-catalog plugin

- **GIVEN** `api_base` is not in the public catalog
- **WHEN** `installIfAvailable('api_base')` is called
- **THEN** returns false
- **AND** last error indicates not in public catalog

### Requirement: Bootstrap system_updater before catalog download

If a catalog download is needed and `system_updater` is not on disk, the **core orchestrator** MUST install it via `PluginInstaller` before retrying `installIfAvailable()`.

The provider itself MUST NOT bootstrap `system_updater`; it only downloads when a catalog entry exists.

#### Scenario: Updater missing

- **GIVEN** `plugins/system_updater/` does not exist
- **AND** `clientes_core` is listed in the public catalog JSON
- **WHEN** `installIfAvailable('clientes_core')` runs through the orchestrator
- **THEN** `system_updater` is installed before the target plugin download

#### Scenario: Non-catalog dependency

- **GIVEN** `api_base` is not in the public catalog
- **WHEN** the orchestrator tries to install it as a missing dependency
- **THEN** `system_updater` MUST NOT be installed solely for that attempt

### Requirement: Provider registration on plugin init

The provider MUST be registered during `system_updater` Init without breaking boot when core registry is absent.

#### Scenario: Old core without registry

- **GIVEN** core without `PluginInstallProviderRegistry`
- **WHEN** system_updater loads
- **THEN** no fatal error; provider skipped
