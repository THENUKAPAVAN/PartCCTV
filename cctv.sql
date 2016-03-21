CREATE TABLE `cam_list` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL,
  `source` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `cam_list` (`id`, `title`, `enabled`, `source`) VALUES
(1, 'TEST 1', 1, 'rtsp://test'),
(2, 'TEST 2', 1, 'rtsp://test');

CREATE TABLE `cam_settings` (
  `param` char(255) NOT NULL,
  `value` char(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `cam_settings` (`param`, `value`) VALUES
('TTL', '1'),
('path', '/media/cctv');


ALTER TABLE `cam_list`
  ADD UNIQUE KEY `id` (`id`);

ALTER TABLE `cam_settings`
  ADD UNIQUE KEY `param` (`param`);


ALTER TABLE `cam_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;