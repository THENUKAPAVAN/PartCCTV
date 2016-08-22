--
-- (c) 2016 m1ron0xFF
--

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `cctv`
--

-- --------------------------------------------------------

--
-- Структура таблицы `cam_list`
--

CREATE TABLE `cam_list` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL,
  `source` varchar(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


-- --------------------------------------------------------

--
-- Структура таблицы `core_settings`
--

CREATE TABLE `core_settings` (
  `param` char(255) NOT NULL,
  `value` char(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Дамп данных таблицы `core_settings`
--

INSERT INTO `core_settings` (`param`, `value`) VALUES
('TTL', '25'),
('path', '/media/cctv'),
('segment_time_min', '60'),
('ffmpeg_bin', 'ffmpeg -hide_banner -loglevel error -i "%SOURCE%" -c copy -map 0 -f segment -segment_time %SEGTIME_SEC% -segment_atclocktime 1 -segment_format mp4 -reset_timestamps 1 -strftime 1 "%REC_PATH%/id%CAM_ID%/%Y-%m-%d_%H-%M-%S.mkv"'),
('motion_bin', ''),
('custom_bin', ''),
('default_handler', 'ffmpeg');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `cam_list`
--
ALTER TABLE `cam_list`
  ADD UNIQUE KEY `id` (`id`);

--
-- Индексы таблицы `core_settings`
--
ALTER TABLE `core_settings`
  ADD UNIQUE KEY `param` (`param`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `cam_list`
--
ALTER TABLE `cam_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
