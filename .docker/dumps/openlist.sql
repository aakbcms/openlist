-- phpMyAdmin SQL Dump
-- version 4.2.12deb2+deb8u2
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Nov 06, 2017 at 01:33 PM
-- Server version: 5.7.19-17-log
-- PHP Version: 5.6.30-0+deb8u1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `openlist_production`
--

-- --------------------------------------------------------

--
-- Table structure for table `elements`
--

CREATE TABLE IF NOT EXISTS `elements` (
`element_id` int(11) NOT NULL,
  `list_id` int(11) NOT NULL,
  `data` text NOT NULL,
  `weight` int(11) NOT NULL DEFAULT '0',
  `modified` int(11) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created` datetime DEFAULT NULL,
  `previous` int(11) NOT NULL DEFAULT '0',
  `library_code` varchar(128) NOT NULL,
  `guid` varchar(36) NOT NULL DEFAULT '',
  `guidprevious` varchar(36) NOT NULL DEFAULT '',
  `guidparent` varchar(36) NOT NULL DEFAULT ''
) ENGINE=InnoDB AUTO_INCREMENT=3612120 DEFAULT CHARSET=utf8;

--
-- Triggers `elements`
--
DELIMITER //
CREATE TRIGGER `set_created_elements` BEFORE INSERT ON `elements`
 FOR EACH ROW SET NEW.created = IFNULL(NEW.created, NOW())
//
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `errorlog`
--

CREATE TABLE IF NOT EXISTS `errorlog` (
`error_id` int(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `message` text NOT NULL,
  `data` text NOT NULL,
  `type` varchar(255) NOT NULL
) ENGINE=InnoDB AUTO_INCREMENT=128210 DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `lists`
--

CREATE TABLE IF NOT EXISTS `lists` (
`list_id` int(11) NOT NULL,
  `owner` varchar(128) NOT NULL,
  `title` varchar(255) NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` int(11) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `type` varchar(255) NOT NULL,
  `data` text NOT NULL,
  `library_code` varchar(128) NOT NULL,
  `guid` varchar(36) NOT NULL DEFAULT ''
) ENGINE=InnoDB AUTO_INCREMENT=7408253 DEFAULT CHARSET=utf8;

--
-- Triggers `lists`
--
DELIMITER //
CREATE TRIGGER `set_created_lists` BEFORE INSERT ON `lists`
 FOR EACH ROW SET NEW.created = IFNULL(NEW.created, NOW())
//
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `m_counter`
--

CREATE TABLE IF NOT EXISTS `m_counter` (
  `list_id` int(11) NOT NULL,
  `elements` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `m_ip_access`
--

CREATE TABLE IF NOT EXISTS `m_ip_access` (
  `ip` char(20) NOT NULL,
  `calls` int(11) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `m_list_permission`
--

CREATE TABLE IF NOT EXISTS `m_list_permission` (
  `list_id` int(11) NOT NULL,
  `permission` enum('private','shared','public') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `m_loan_history`
--

CREATE TABLE IF NOT EXISTS `m_loan_history` (
  `owner` varchar(128) NOT NULL,
  `object_id` char(32) NOT NULL,
  `created` varchar(6) NOT NULL,
  `library_code` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `m_slow_logger`
--

CREATE TABLE IF NOT EXISTS `m_slow_logger` (
`id` int(11) NOT NULL,
  `stamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `execution` decimal(10,7) NOT NULL,
  `method` char(255) NOT NULL,
  `arguments` text NOT NULL,
  `caller` char(255) NOT NULL
) ENGINE=InnoDB AUTO_INCREMENT=8956 DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `m_tingobject_popularity`
--

CREATE TABLE IF NOT EXISTS `m_tingobject_popularity` (
  `object_id` char(20) NOT NULL,
  `popularity` decimal(10,2) NOT NULL,
  `modified` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `m_tingobject_rating`
--

CREATE TABLE IF NOT EXISTS `m_tingobject_rating` (
  `owner` varchar(128) NOT NULL,
  `object_id` char(128) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `created` varchar(6) NOT NULL,
  `library_code` varchar(128) NOT NULL,
  `tstamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `user_provider`
--

CREATE TABLE IF NOT EXISTS `user_provider` (
  `owner` varchar(128) NOT NULL,
  `library_code` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `elements`
--
ALTER TABLE `elements`
 ADD PRIMARY KEY (`element_id`), ADD KEY `list_id` (`list_id`), ADD KEY `previous` (`previous`), ADD KEY `guid` (`guid`);

--
-- Indexes for table `errorlog`
--
ALTER TABLE `errorlog`
 ADD PRIMARY KEY (`error_id`);

--
-- Indexes for table `lists`
--
ALTER TABLE `lists`
 ADD PRIMARY KEY (`list_id`), ADD KEY `owner` (`owner`), ADD KEY `guid` (`guid`);

--
-- Indexes for table `m_counter`
--
ALTER TABLE `m_counter`
 ADD PRIMARY KEY (`list_id`);

--
-- Indexes for table `m_ip_access`
--
ALTER TABLE `m_ip_access`
 ADD PRIMARY KEY (`ip`);

--
-- Indexes for table `m_list_permission`
--
ALTER TABLE `m_list_permission`
 ADD PRIMARY KEY (`list_id`);

--
-- Indexes for table `m_loan_history`
--
ALTER TABLE `m_loan_history`
 ADD PRIMARY KEY (`owner`,`object_id`,`library_code`);

--
-- Indexes for table `m_slow_logger`
--
ALTER TABLE `m_slow_logger`
 ADD PRIMARY KEY (`id`);

--
-- Indexes for table `m_tingobject_popularity`
--
ALTER TABLE `m_tingobject_popularity`
 ADD PRIMARY KEY (`object_id`);

--
-- Indexes for table `m_tingobject_rating`
--
ALTER TABLE `m_tingobject_rating`
 ADD PRIMARY KEY (`owner`,`object_id`), ADD KEY `owner_rating` (`owner`,`rating`);

--
-- Indexes for table `user_provider`
--
ALTER TABLE `user_provider`
 ADD PRIMARY KEY (`owner`,`library_code`);

