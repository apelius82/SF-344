# SafetyFlash Application

## Overview

SafetyFlash is a modern web-based safety communication platform designed to manage the complete lifecycle of safety-related events from initial reporting to investigation, publication, distribution, and analytics.

The system provides a centralized, structured, and fully traceable workflow for safety communication across the organization.

SafetyFlash is available as a Progressive Web Application (PWA) and supports desktop, tablet, and mobile devices.

---

## Supported SafetyFlash Types

### First Release

Used when an accident or incident requires medical treatment.

### Dangerous Situation

Used when a hazardous situation, equipment damage, or non-treatment injury occurs.

### Investigation Report

Created after an investigation has been completed.

Investigation Reports are normally created as a continuation of an existing First Release or Dangerous Situation report to ensure full traceability throughout the incident lifecycle.

---

## Key Capabilities

- Structured safety communication workflow
- Visual SafetyFlash card generation
- Multi-stage approval process
- Investigation management
- Version history and audit trail
- Dashboard and analytics
- Push and email notifications
- Multi-language publishing
- Worksite-specific workflows
- Direct digital signage distribution
- Xibo integration
- Progressive Web Application support

---

# SafetyFlash Lifecycle

The SafetyFlash process follows a structured lifecycle from event reporting to final publication and investigation.

## 1. Event Occurs

An accident, injury, dangerous situation, or other safety-related event occurs.

## 2. Creation

The creator prepares the initial SafetyFlash including:

- Description
- Event details
- Images
- Injury information
- Worksite information

## 3. Supervisor Review

The Site Supervisor reviews the content and approves it for the next stage.

Workflow stages can be automatically skipped when configured.

## 4. Safety Team Review

The Safety Team validates:

- Classification
- Correctness
- Required actions
- Investigation requirements

## 5. Communication & Localization

Communications can:

- Finalize content
- Review wording
- Create language versions
- Prepare publication

## 6. Publication

SafetyFlash can be published:

- Inside the application
- Via email notifications
- Via push notifications
- To worksite display systems
- To selected worksites

## 7. Investigation

If required, an Investigation Report is created.

The investigation remains linked to the original SafetyFlash throughout its lifecycle.

---

# Core Features

## Creation & Content Management

### Multi-Step Creation Process

SafetyFlash creation includes:

1. Type Selection
2. Location & Time
3. Content
4. Images
5. Layout
6. Preview & Publishing

### Draft Saving

Features include:

- Automatic saving
- Temporary storage
- Recovery protection
- Edit session persistence

### Image Editing & Annotation

Built-in image editor supports:

- Drawing tools
- Markers
- Highlights
- Blur tool
- Annotations
- Mobile editing

All annotations are embedded into the final image.

### Image Processing

Features include:

- Preview image generation
- Automatic rendering
- Thumbnail creation
- Mobile zoom support
- Pinch zoom support
- Smooth image navigation

### Additional Information

Supports rich-text content for extended information.

Additional information can be displayed:

- Inside SafetyFlash
- In reports
- In PDF exports

---

# Workflow & Collaboration

## Structured Approval Workflow

Typical workflow:

1. Creator
2. Site Supervisor
3. Safety Team
4. Communications
5. Publication

Workflow steps can be:

- Approved
- Rejected
- Returned
- Skipped

Skipped stages are visually indicated in the process diagram.

---

## User Roles

### Administrator

Full system access and configuration rights.

### Site Supervisor

Reviews and approves SafetyFlashes assigned to the worksite.

### Safety Team

Validates content, investigations, and safety processes.

### Communications

Manages language versions and publication.

### User

Creates SafetyFlashes and participates in workflow activities.

---

## Process Tracking

Each SafetyFlash contains:

- Workflow history
- Process timeline
- Approval records
- User actions
- Publication history
- Investigation links

All actions are logged.

---

## Comments & Feedback

Users can:

- Add comments
- Review discussions
- Track actions
- Monitor corrective measures

All activity is stored in the SafetyFlash history.

---

## Corrective Actions

SafetyFlash supports corrective action management.

Features include:

- Action assignment
- Status tracking
- Progress monitoring
- Historical records

---

## Version History

The system maintains:

- Version history
- Investigation links
- Edit history
- Workflow history
- Publication history

Full traceability is available throughout the lifecycle.

---

# Injury Tracking

## Injury Data Collection

Supported information includes:

- Body part
- Injury classification
- Event type

## Dashboard Heatmap

The dashboard provides:

- Human body heatmap
- Injury distribution
- Historical injury tracking
- Worksite filtering
- Time filtering

---

# Dashboard & Analytics

## Dashboard

The dashboard includes:

- Published SafetyFlashes
- First Releases
- Dangerous Situations
- Investigation Reports
- Injury tracking
- Worksite statistics

---

## Analytics

The analytics system provides:

- User activity tracking
- Publication statistics
- Notification analytics
- Display analytics
- Usage trends
- Historical reporting

Analytics data supports long-term safety monitoring and reporting.

---

# Notifications

## Email Notifications

Users can receive email notifications for:

- Review requests
- Returned SafetyFlashes
- Publications
- Investigation Reports
- Comments
- Workflow changes

---

## Push Notifications

SafetyFlash supports real-time push notifications.

Supported platforms:

- Android
- iPhone
- Desktop browsers

Users can manage notification preferences from their profile.

Notification channels can be configured separately for:

- Email notifications
- Push notifications

Push notifications continue to function even when the application is not actively open.

---

## System Notices

Administrators can publish system-wide notices to inform users about:

- Maintenance windows
- Service disruptions
- New features
- Important announcements

System notices are displayed directly within the application.

---

# Multi-Language Support

SafetyFlash supports:

- Finnish
- Swedish
- English
- Italian
- Greek

Language versions inherit content from the original SafetyFlash and can be translated independently.

The system supports localized terminology and workflow translations.

---

# Worksite Management

Features include:

- Multiple worksites
- Site supervisors
- Worksite permissions
- Worksite filtering
- Default display settings
- Worksite-specific publication targets

---

# Display System & Xibo Integration

## What is Xibo?

Xibo is an open-source digital signage platform used to manage and display content on information screens, televisions, and display networks across an organization.

SafetyFlash integrates directly with Xibo, allowing published SafetyFlashes to be automatically distributed to selected display screens without manual intervention.

---

## Overview

Published SafetyFlashes can be automatically distributed to screens across the organization.

Typical display locations include:

- Worksite information screens
- Break rooms
- Control rooms
- Reception areas
- Production facilities

---

## Features

- Playlist management
- Display targeting
- Worksite targeting
- Scheduling
- Automatic updates
- TTL management
- API-based delivery
- Display filtering

---

## Display Workflow

1. SafetyFlash is published
2. Display content is generated
3. Content is exposed through the Display API
4. Xibo retrieves updated content
5. Displays update automatically

---

# Athena Export Tracking

SafetyFlash includes Athena export tracking functionality.

Features include:

- Export reminders
- Export status indicators
- Export logging
- User tracking
- Export history

This ensures critical reports are transferred to external systems when required.

---

# Progressive Web Application (PWA)

SafetyFlash can be installed directly as an application.

Supported platforms:

- Android
- iPhone
- Windows
- macOS

Benefits include:

- Native-like experience
- Home screen installation
- Push notifications
- Faster loading
- Mobile-optimized interface

---

# Security

Security features include:

- Authentication
- Role-based permissions
- Email domain restrictions
- Input validation
- File upload validation
- API authentication
- Activity logging

---

# Technical Architecture

## Backend

- PHP 8+
- MySQL

## Frontend

- Vanilla JavaScript
- Responsive CSS
- Progressive Web Application

## Services

- Push Notification Service
- Email Notification Service
- Analytics Service
- Preview Rendering Service
- Display API Service

---

# Data Flow

User Input

→ Validation

→ Temporary Storage

→ Final Save

→ Rendering

→ Workflow Processing

→ Publication

→ Analytics

---

# Background Processing

Scheduled jobs handle:

- Temporary file cleanup
- Preview generation
- Analytics aggregation
- Notification processing
- Display synchronization

Cron jobs must be configured on the server.

---

# Project Structure

```text
/app/
    /controllers/
    /views/
    /cron/

/assets/
    /css/
    /js/
    /pages/

/uploads/
    /images/
    /processes/

/storage/
    /logs/

index.php
config.php
manifest.php
sw.js.php
```
