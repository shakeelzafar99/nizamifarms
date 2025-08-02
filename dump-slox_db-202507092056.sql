-- MySQL dump 10.13  Distrib 8.4.5, for Win64 (x86_64)
--
-- Host: localhost    Database: slox_db
-- ------------------------------------------------------
-- Server version	8.4.5

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `t_crm_branch`
--

DROP TABLE IF EXISTS `t_crm_branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `t_crm_branch` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `branch_name` varchar(50) NOT NULL,
  `email` varchar(50) DEFAULT NULL,
  `contact_person` varchar(50) NOT NULL,
  `contact_no` varchar(20) NOT NULL,
  `address_first_line` varchar(150) NOT NULL,
  `address_second_line` varchar(150) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postcode` varchar(50) DEFAULT NULL,
  `logo` varchar(100) DEFAULT NULL,
  `short_code` varchar(10) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `is_active` char(1) NOT NULL DEFAULT 'Y',
  `created_at` datetime NOT NULL,
  `created_by` int NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `mobile_no` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `t_crm_branch`
--

LOCK TABLES `t_crm_branch` WRITE;
/*!40000 ALTER TABLE `t_crm_branch` DISABLE KEYS */;
INSERT INTO `t_crm_branch` VALUES (1,1,'WTD - BR-1',NULL,'Ali Arshad','0412123213213','line 1 address','line 2 address','London','46000',NULL,NULL,'This is the first branch','Y','2025-02-13 19:19:18',1,'2025-02-13 19:19:18',NULL,'0412123213213'),(2,1,'WTD - BR-2',NULL,'Shakeel Zafar','03153000210','Flat # 2, Block B9, street # 7, G-15/4, khayaban-e-','line 2 address','ISLAMABAD','46000',NULL,NULL,'test','Y','2025-04-12 17:41:11',1,'2025-04-12 17:41:11',NULL,'03153000210'),(3,2,'XZ-1',NULL,'Ahmed ali','0123123123','Flat # 2, Block B9, street # 7, G-15/4, khayaban-e-','line 2 address','ISLAMABAD','46000',NULL,NULL,'This is test branch','Y','2025-06-29 19:24:36',1,'2025-06-29 19:24:36',NULL,'32432432324'),(4,2,'XZ-2',NULL,'Ahmed ali','0123123123','Flat # 2, Block B9, street # 7, G-15/4, khayaban-e-','line 2 address','ISLAMABAD','46000',NULL,NULL,'This is test branch','Y','2025-06-29 19:25:12',1,'2025-06-29 19:25:12',NULL,'32432432324');
/*!40000 ALTER TABLE `t_crm_branch` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-07-09 20:56:20
