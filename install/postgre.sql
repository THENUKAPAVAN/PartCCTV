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
CREATE TABLE cam_settings (
  param char(255) NOT NULL,
  value char(255) NOT NULL,
  CONSTRAINT param UNIQUE  (param)
) ;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO cam_settings VALUES ('TTL','25'),('path','/media/cctv'),('segment_time_min','60');
