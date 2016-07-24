PartCCTV, Yet Another CCTV App
==================

[![SensioLabsInsight](https://insight.sensiolabs.com/projects/6308734b-20af-4963-b73e-a1c860cfb595/mini.png)](https://insight.sensiolabs.com/projects/6308734b-20af-4963-b73e-a1c860cfb595)

## Features
  - Lightweight
  - Open Source
  - Made with love :)  

## Installation
  - `git clone`
  - Configure `nginx` (using `install/nginx.conf` as example) or configure `Apache` (no example config: TBD)
  - Restore DB from .sql file (using `install/mysql.sql`, `install/postgre.sql` or converting it to another DB)
  - Configure `PartCCTV.ini` file
  - Run core: `php PartCCTV/server/starter.php` (TBD: systemd service)
  - Set-up core from web_gui or API
  - That's all :)
  

## About

### Requirements
  - Linux/FreeBSD/MacOSX ( except Windows because of pcntl_fork() )
  - PHP 7.0 with PDO and ZeroMQ binding ( http://zeromq.org/bindings:php )
  - ZeroMQ ( http://zeromq.org/area:download )
  - PDO compatible DB (MySQL, Postgresql, SQlite, etc.)
  - FFmpeg

### Contributing
  - Fork it ( https://github.com/mironoff111/PartCCTV/fork )
  - Create your feature branch (`git checkout -b my-new-feature`)
  - Commit your changes (`git commit -am 'Add some feature'`)
  - Push to the branch (`git push origin my-new-feature`)
  - Create a new Pull Request

### License

PartCCTV is licensed under the CC BY-NC-SA 4.0 License - see the `LICENSE.md` file for details
