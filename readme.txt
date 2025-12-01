=== SnapCache ===
Contributors: staticwebio
Tags: performance, speed, memcached, object cache
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 0.2.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A high-performance persistent object cache powered by Memcached.

== Description ==

SnapCache accelerates WordPress by providing a fast, reliable persistent object cache backed by Memcached.
It dramatically reduces database load, speeds up page generation, and improves scalability under heavy traffic.

SnapCache is designed to be safer and faster than existing
object cache plugins. It will not break your site if the cache is unreachable, and is markedly faster than other popular object caches (see Benchmarks).

= Features =

* Persistent object cache using Memcached
* Automatic prefetching of frequently accessed keys
* WP-CLI commands for inspecting and managing the cache
* Continues to operate even if Memcached is unreachable

= Benchmarks =

Requests per second in a default WordPress installation. Comparison of object cache functionality only.

* **SnapCache v0.2.0 with Memcached - 387.0 req/sec**
* Redis Cache v2.7.0 - 367.1 req/sec
* LiteSpeed v7.6.2 with Redis - 218.6 req/sec
* LiteSpeed v7.6.2 with Memcached - 218.0 req/sec

== Requirements ==

* WordPress 6.4 or later
* PHP 8.1+ with the Memcached extension installed
* At least one accessible Memcached server

== Changelog ==

Full changelog available at https://github.com/staticweb-io/snapcache/blob/master/CHANGELOG.md

= 0.2.0 =
Initial submission to WordPress.org.

== Upgrade Notice ==

= 0.2.0 =
Initial submission to WordPress.org.
