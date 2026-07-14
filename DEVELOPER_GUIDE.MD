# Developer Guide – SafetyFlash

## Purpose

This document describes the internal architecture, technical implementation, development principles and maintenance practices of the SafetyFlash platform.

For end-user features and system overview, see README.md.

---

# Technology Stack

## Backend

- PHP 8+
- MySQL / MariaDB
- PDO database layer

## Frontend

- Vanilla JavaScript
- CSS3
- HTML5

## Platform Features

- Progressive Web Application (PWA)
- Push Notifications
- Responsive UI
- Mobile-first design

---

# Project Structure

## Root Directories

/app
/assets
/config
/uploads
/views

---

## App Directory

Contains business logic and application services.

Typical modules:

- Controllers
- Services
- AJAX endpoints
- Cron jobs

---

## Assets Directory

Contains frontend resources.

### CSS

/assets/css/

### JavaScript

/assets/js/

### Images

/assets/img/

### Icons

/assets/img/icons/

---

## Configuration Directory

/config/

Contains:

- Application settings
- Environment configuration
- Translation terms

---

## Translation System

All user-facing strings must use the translation system.

Location:

/config/terms/

Supported languages:

- Finnish
- Swedish
- English
- Italian
- Greek

Development rule:

Every new UI text must be added to every language file.

Hardcoded user-facing text should be avoided.

---

# SafetyFlash Types

## First Release

Used for:

- Accidents
- Incidents requiring medical treatment

Purpose:

- Immediate communication

---

## Dangerous Situation

Used for:

- Near misses
- Property damage
- Personal injuries not requiring treatment

Purpose:

- Prevent future accidents

---

## Investigation Report

Created as a continuation of:

- First Release
- Dangerous Situation

Purpose:

- Root cause analysis
- Corrective actions
- Final documentation

---

# Workflow Architecture

The workflow engine controls SafetyFlash progression.

Typical flow:

Reporter
→ Site Supervisor
→ Safety Team
→ Communications
→ Published

The workflow can dynamically skip stages depending on configuration and publication method.

Skipped stages must be visible in the process diagram.

---

# User Roles

The application supports role-based permissions.

Examples:

- Reporter
- Site Supervisor
- Safety Team
- Communications
- Administrator

Roles control:

- Available actions
- Workflow transitions
- Notification recipients
- Data visibility

---

# Form System

Main entry point:

form.php

Responsibilities:

- Multi-step form flow
- Validation
- Draft handling
- Temporary storage
- Image upload
- Injury registration
- Additional information

---

# Temporary Process Storage

Location:

/uploads/processes/

Used for:

- Uploaded images
- Draft data
- Intermediate files

Purpose:

- Prevent data loss during editing

Cleanup is handled by scheduled background jobs.

---

# Image System

Supports:

- Upload
- Preview
- Annotation
- Blur tool
- Mobile zoom
- Pinch zoom
- Image panning

Requirements:

- Persist through edits
- Persist through investigation creation
- Persist through PDF generation

Images must never rely solely on session storage.

---

# Injury Registration System

Stores structured injury information.

Examples:

- Body part
- Injury type
- Severity

Used by:

- Dashboard
- Analytics
- Reporting

All injury data should remain structured and queryable.

---

# Preview Engine

Purpose:

Generate live SafetyFlash previews before publication.

Supports:

- Desktop
- Tablet
- Mobile

Features:

- Pinch zoom
- Touch panning
- Momentum scrolling

Preview rendering should match published output.

---

# Rendering Engine

Responsible for:

- SafetyFlash cards
- Preview cards
- PDF cards
- Display cards

Rendering consistency must be maintained across:

- Form preview
- View page
- PDF export
- Display mode

---

# Investigation Card Splitting

Applies only to:

- Investigation Reports

Purpose:

- Improve readability
- Fit display layouts
- Improve PDF rendering

Requirements:

- Automatic splitting
- Manual override support
- Consistent numbering

---

# View Page

Main entry point:

view.php

Responsibilities:

- Display SafetyFlash content
- Process diagram
- Workflow history
- Attachments
- Feedback integration

---

# Process Diagram

Displays workflow progress.

Requirements:

- Active stage
- Completed stages
- Skipped stages
- Timestamps
- Assigned users

Tooltip information must remain synchronized with workflow history.

---

# Dashboard System

Main entry point:

dashboard.php

Provides:

- KPI metrics
- Injury statistics
- Trends
- Workflow metrics

Performance requirement:

Prefer SQL aggregation over large PHP loops.

---

# Analytics System

Purpose:

Track platform usage and engagement.

Examples:

- Views
- Downloads
- Shares
- Playlist displays
- Push interactions

Analytics data must remain separate from operational SafetyFlash records.

---

# Feedback System

Main entry point:

feedback.php

Supports:

- Comments
- Follow-up actions
- Corrective actions
- Status tracking

Requirements:

- AJAX updates
- Mobile support
- Permission control

---

# Notification Architecture

Supports:

## Email Notifications

Used for:

- Workflow assignments
- Publications
- Feedback events
- Returned SafetyFlashes

---

## Push Notifications

Supported platforms:

- Android
- iOS
- Desktop browsers

Notification settings are managed from user profiles.

Users can independently control:

- Email notifications
- Push notifications

---

# PWA Architecture

Components:

- Manifest
- Service Worker
- Offline caching
- Push subscriptions

Requirements:

- Android support
- iOS support
- Installation support

---

# Display System

Purpose:

Present SafetyFlashes on information displays.

Features:

- Site-specific displays
- Playlist support
- Automatic rotation
- Analytics tracking

---

# Playlist System

Responsibilities:

- SafetyFlash ordering
- Scheduling
- Expiration handling
- Site filtering

Metadata shown on screen must always match the currently active SafetyFlash.

---

# PDF Generation

Generated PDFs must preserve:

- Images
- Annotations
- Investigation content
- Additional information

Output must match published content.

---

# Background Jobs

Location:

/app/cron/

Responsibilities:

- Temporary file cleanup
- Preview generation
- Analytics maintenance
- Scheduled processing

Cron execution is required for proper operation.

---

# Debugging Guidelines

Verify:

- Database references
- Uploaded files
- Notification queues
- Workflow logs
- Analytics records
- Preview generation

Common locations:

/uploads/processes/
/uploads/previews/
/uploads/pdf/

---

# Development Rules

## Never Assume

Changes must always be based on:

- Existing source code
- Existing workflow logic
- Existing database structure

Never make assumptions about implementation details.

---

## Maintain Compatibility

New features must not break:

- Existing SafetyFlashes
- Existing workflows
- Existing notifications
- Existing analytics data

---

## Maintain Translation Coverage

Every new term must be added to:

/config/terms/

before deployment.

---

## Mobile First

Every new feature must support:

- Desktop
- Tablet
- Mobile

---

## Modern UI Principles

Follow:

- Responsive layouts
- Touch-friendly interactions
- Consistent spacing
- Accessibility standards

---

# Deployment Checklist

Before release verify:

- Database migrations completed
- Translation files updated
- Push notifications tested
- Email notifications tested
- Mobile layouts verified
- PWA installation verified
- Analytics tracking verified

---

# Summary

SafetyFlash is a workflow-driven safety communication platform combining reporting, investigation, publication, feedback, analytics, display management and push notifications within a unified multilingual environment.

Primary development goals:

- Reliability
- Traceability
- Performance
- Mobile usability
- Consistent user experience
- Long-term maintainability
