-- phpMyAdmin SQL Dump
-- version 4.0.4.1
-- http://www.phpmyadmin.net
--
-- Host: 127.0.0.1
-- Generation Time: Mar 06, 2014 at 03:39 AM
-- Server version: 5.6.11
-- PHP Version: 5.5.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `your_database`
--
CREATE DATABASE IF NOT EXISTS `your_database` DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;
USE `your_database`;

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `AdjustRanks`(IN uid BIGINT UNSIGNED)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE mlid BIGINT UNSIGNED;
    DECLARE rank TINYINT UNSIGNED DEFAULT 0;
    DECLARE cur1 CURSOR FOR SELECT `movie_list_id` FROM `movie_lists` WHERE `user_id` = uid ORDER BY `list_rank` ASC;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur1;
    
    loop1: LOOP

      FETCH cur1 INTO mlid;
      IF done THEN
        LEAVE loop1;
      END IF;
      UPDATE `movie_lists` SET `list_rank` = rank WHERE `movie_list_id` = mlid;
      SET rank = rank + 1;
    END LOOP;
    
    CLOSE cur1;
  END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `master_genre_list`
--

CREATE TABLE IF NOT EXISTS `master_genre_list` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tmdb_movie_id` bigint(20) unsigned NOT NULL,
  `tmdb_genre_id` mediumint(8) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tmdb_movie_id` (`tmdb_movie_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `master_movie_list`
--

CREATE TABLE IF NOT EXISTS `master_movie_list` (
  `tmdb_movie_id` bigint(20) unsigned NOT NULL,
  `tmdb_title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `tmdb_original_title` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `tmdb_poster_path` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `tmdb_release_date` date NOT NULL,
  PRIMARY KEY (`tmdb_movie_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `movies`
--

CREATE TABLE IF NOT EXISTS `movies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `movie_list_id` bigint(20) unsigned NOT NULL,
  `tmdb_movie_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `movie_list_id` (`movie_list_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=499 ;

-- --------------------------------------------------------

--
-- Table structure for table `movie_lists`
--

CREATE TABLE IF NOT EXISTS `movie_lists` (
  `movie_list_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `list_name` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `list_description` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `list_rank` tinyint(3) unsigned NOT NULL,
  `share` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`movie_list_id`),
  KEY `user_id` (`user_id`),
  KEY `user_id_2` (`user_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=98 ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'auto incrementing user_id of each user, unique index',
  `user_name` varchar(64) COLLATE utf8_unicode_ci NOT NULL COMMENT 'user''s name',
  `user_password_hash` char(60) COLLATE utf8_unicode_ci NOT NULL COMMENT 'user''s password in salted and hashed format',
  `user_email` varchar(64) COLLATE utf8_unicode_ci NOT NULL COMMENT 'user''s email',
  `user_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'user''s activation status',
  `user_activation_hash` varchar(40) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'user''s email verification hash string',
  `user_password_reset_hash` char(40) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'user''s password reset code',
  `user_password_reset_timestamp` bigint(20) DEFAULT NULL COMMENT 'timestamp of the password reset request',
  `user_rememberme_token` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'user''s remember-me cookie token',
  `user_registration_datetime` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_registration_ip` varchar(39) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0.0.0.0',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_name` (`user_name`),
  UNIQUE KEY `user_email` (`user_email`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='user data' AUTO_INCREMENT=38 ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
