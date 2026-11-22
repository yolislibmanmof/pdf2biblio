-- PDF2Biblio Batch B: manifest impor (fondasi undo batch & laporan).
-- Opsional: Api B-2 juga membuat tabel ini lazy (CREATE TABLE IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS `plugin_pdf2biblio_batch` (
  `batch_id` varchar(32) NOT NULL,
  `uid` int(11) NOT NULL DEFAULT 0,
  `label` varchar(60) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `summary` text NULL,
  PRIMARY KEY (`batch_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plugin_pdf2biblio_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(32) NOT NULL,
  `biblio_id` int(11) NOT NULL DEFAULT 0,
  `token` varchar(16) NOT NULL DEFAULT '',
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(10) NOT NULL DEFAULT '',
  `msg` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `biblio_id` (`biblio_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;