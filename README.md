# WireCache Filesystem

**ProcessWire WireCache module that replaces the default cache handler with a 
file system based cache.**

> This module requires ProcessWire 3.0.218 or newer. Do not attempt to install
it on prior versions as the necessary interfaces do not exist prior to 3.0.218.

To use this module, you don't need to do anything other than install it. Once
installed, it takes over the cache storage for the 
[$cache API variable](https://processwire.com/api/ref/wire-cache/), moving it 
to the file system. When you uninstall the module, cache storage moves back 
to the database.

Depending on the environment, the file system based cache may be 
potentially faster than the database cache in some instances (such as reads), 
or slower in others (such as writes). 

This module stores cache files in the following directory:
`/site/assets/cache/WireCache/`

This module is also meant as an example implementation of the
`WireCacheInterface` for other modules. The core 
`/wire/core/WireCacheDatabase.php` is also a good one to look at since a
lot of the code in this Filesystem module ends up being file-system related,
and `WireCacheDatabase` may be a little simpler in communicating some parts.

### Installation

- Copy the module files into `/site/modules/WireCacheFilesystem/`
- In your admin, go to Modules > Refresh.
- Click "install" for this module.
- That's it.
 
### Getting and saving caches

Please see the [$cache API variable](https://processwire.com/api/ref/wire-cache/)
for details on getting and saving caches.

### Clearing the cache

It is okay to delete the `/site/assets/cache/WireCache/` directory
as this module will re-create it automatically. Other than that, you 
can also always use the `$cache->deleteAll()` core API method to clear 
the cache. 

---
Copyright 2023 by Ryan Cramer Design, LLC / ProcessWire