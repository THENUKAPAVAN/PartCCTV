/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE SEQUENCE cam_list_seq;

CREATE TABLE cam_list (
  id int NOT NULL DEFAULT NEXTVAL ('cam_list_seq'),
  title varchar(255) NOT NULL,
  enabled smallint NOT NULL,
  source varchar(255) NOT NULL,
  CONSTRAINT id UNIQUE  (id)
)  ;

ALTER SEQUENCE cam_list_seq RESTART WITH 1;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE core_settings (
  param char(255) NOT NULL,
  value char(255) NOT NULL,
  CONSTRAINT param UNIQUE  (param)
) ;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO core_settings VALUES 
('TTL', '25'),
('path', '/media/cctv'),
('segment_time_min', '60'),
('ffmpeg_bin', 'ffmpeg -hide_banner -loglevel error -i "%SOURCE%" -c copy -map 0 -f segment -segment_time %SEGTIME_SEC% -segment_atclocktime 1 -segment_format mp4 -reset_timestamps 1 -strftime 1 "%REC_PATH%/id%CAM_ID%/%Y-%m-%d_%H-%M-%S.mkv"'),
('motion_bin', ''),
('custom_bin', ''),
('default_handler', 'ffmpeg');
