# Sosyomat.com Redesign Roadmap

## Project Overview
Transform the current social media platform to match the clean, minimalist design of sosyomat.com (circa 2010), featuring a simple green/white color scheme and tag-based navigation.

**Start Date**: February 2, 2026  
**Target Completion**: March 15, 2026 (6 weeks)  
**Status**: 🟡 In Progress

---

## Design Inspiration

### Sosyomat.com Key Features
- ✅ Clean, minimalist interface
- ✅ Green (#5a9a3c) as primary color
- ✅ Tag-based content organization
- ✅ Simple typography (Helvetica/Arial)
- ✅ Three-column layout (left sidebar, main, right sidebar)
- ✅ List-based post display
- ✅ Minimal use of borders/shadows
- ✅ Text-focused content (not image-heavy)

---

## Phase 1: Core Design System (Week 1) ✅

### Week 1 Tasks
- [x] Create `sosyomat.css` stylesheet
- [x] Define color palette
  - Primary: #5a9a3c (green)
  - Background: #f5f5f5 (light gray)
  - Text: #333 (dark gray)
  - Borders: #ddd (light gray)
- [x] Set typography
  - Font: Helvetica Neue, Arial, sans-serif
  - Base size: 13px
- [x] Create layout grid (200px-1fr-200px)
- [x] Design header component
- [x] Design footer component

**Deliverables**:
- ✅ `/assets/css/sosyomat.css`
- ✅ Color scheme documentation
- ✅ Typography guidelines

---

## Phase 2: Template Components (Week 2)

### Week 2 Tasks
- [x] Create new index page (`index_sosyomat.php`)
- [x] Create header template (`header_sosyomat.php`)
- [x] Create footer template (`footer_sosyomat.php`)
- [x] Create post card template (`post-card-sosyomat.php`)
- [ ] Update navigation structure
- [ ] Implement search bar in header
- [ ] Create sidebar components
  - [ ] Newest users widget
  - [ ] Active users widget
  - [ ] Popular users widget
  - [ ] Announcements widget
  - [ ] Tag cloud widget

**Deliverables**:
- ✅ 4 new template files
- 🔄 Sidebar widgets (in progress)
- 🔄 Search functionality

---

## Phase 3: Page Integration (Week 3)

### Week 3 Tasks
- [ ] Convert main pages to sosyomat style:
  - [ ] Profile page (`profile.php`)
  - [ ] Post detail page (`post.php`)
  - [ ] Search page (`search.php`)
  - [ ] Events page (`events.php`)
  - [ ] Premium page (`premium.php`)
- [ ] Update forms styling:
  - [ ] Login form
  - [ ] Register form
  - [ ] Post creation form
  - [ ] Comment form
- [ ] Update buttons and inputs
- [ ] Test responsive design

**Deliverables**:
- [ ] 5+ converted pages
- [ ] Form styling guide
- [ ] Mobile responsiveness

---

## Phase 4: Interactive Elements (Week 4)

### Week 4 Tasks
- [ ] Implement tag navigation
  - [ ] Tag filtering
  - [ ] Tag autocomplete
  - [ ] Popular tags display
- [ ] Add AJAX interactions:
  - [ ] Like button (no page reload)
  - [ ] Comment submission
  - [ ] Follow/unfollow
  - [ ] Real-time search
- [ ] Implement pagination
- [ ] Add loading states
- [ ] Add empty states

**Deliverables**:
- [ ] Tag system functionality
- [ ] AJAX-enhanced interactions
- [ ] Loading/empty states

---

## Phase 5: Data Integration (Week 5)

### Week 5 Tasks
- [ ] Create announcements table/system
- [ ] Implement tag extraction from posts
- [ ] Create tag relationships
- [ ] Build trending tags algorithm
- [ ] Add user activity tracking
- [ ] Create "active users" query
- [ ] Optimize database queries
- [ ] Add caching layer

**Database Changes**:
```sql
-- Tags table
CREATE TABLE tags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE,
    post_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Post tags relationship
CREATE TABLE post_tags (
    post_id INT,
    tag_id INT,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, tag_id)
);

-- Announcements table
CREATE TABLE announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200),
    content TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Deliverables**:
- [ ] Database migrations
- [ ] Tag system backend
- [ ] Announcements system
- [ ] Performance optimizations

---

## Phase 6: Testing & Polish (Week 6)

### Week 6 Tasks
- [ ] Cross-browser testing
  - [ ] Chrome
  - [ ] Firefox
  - [ ] Safari
  - [ ] Edge
- [ ] Mobile testing
  - [ ] iOS Safari
  - [ ] Android Chrome
- [ ] Accessibility audit
  - [ ] Screen reader testing
  - [ ] Keyboard navigation
  - [ ] Color contrast
- [ ] Performance testing
  - [ ] Page load times
  - [ ] Database query optimization
  - [ ] Image optimization
- [ ] User acceptance testing
- [ ] Bug fixes
- [ ] Documentation updates

**Deliverables**:
- [ ] Test results document
- [ ] Bug fix list
- [ ] Performance report
- [ ] User guide

---

## Migration Plan

### Option 1: Gradual Rollout
1. Keep existing design as default
2. Add sosyomat as alternative theme
3. Allow users to switch in settings
4. Monitor usage and feedback
5. Make sosyomat default after 2 weeks
6. Deprecate old theme after 1 month

### Option 2: Hard Switch
1. Backup current design files
2. Deploy sosyomat design
3. Redirect all pages to new design
4. Monitor for issues
5. Rollback if critical bugs found

**Recommended**: Option 1 (Gradual Rollout)

---

## File Structure

```
/textsocialmedia/
├── assets/
│   └── css/
│       ├── style.css (old)
│       └── sosyomat.css (new) ✅
├── includes/
│   ├── header.php (old)
│   ├── header_sosyomat.php (new) ✅
│   ├── footer.php (old)
│   └── footer_sosyomat.php (new) ✅
├── templates/
│   ├── post-card.php (old)
│   └── post-card-sosyomat.php (new) ✅
├── index.php (old)
├── index_sosyomat.php (new) ✅
├── profile.php
├── profile_sosyomat.php (to create)
├── search.php
├── search_sosyomat.php (to create)
└── ...
```

---

## Design Specifications

### Colors
| Element | Color | Hex |
|---------|-------|-----|
| Primary | Green | #5a9a3c |
| Primary Dark | Dark Green | #4a8a2c |
| Background | Light Gray | #f5f5f5 |
| Content BG | White | #ffffff |
| Text | Dark Gray | #333333 |
| Text Light | Gray | #666666 |
| Text Muted | Light Gray | #999999 |
| Border | Very Light Gray | #dddddd |
| Border Light | Almost White | #eeeeee |
| Error | Red | #e74c3c |
| Success | Green | #5a9a3c |
| Warning | Yellow | #ffc107 |

### Typography
- **Font Family**: 'Helvetica Neue', Arial, sans-serif
- **Base Size**: 13px
- **Line Height**: 1.6
- **Headings**: Bold, slightly larger
- **Links**: #5a9a3c, underline on hover

### Spacing
- **Container Max Width**: 980px
- **Grid Gap**: 20px
- **Section Padding**: 15px
- **Small Gap**: 8px
- **Medium Gap**: 15px
- **Large Gap**: 20px

### Components

#### Header
- Background: White
- Border Bottom: 3px solid #5a9a3c
- Height: Auto (fluid)
- Logo: 28px, bold, #5a9a3c

#### Sidebar
- Background: White
- Border: 1px solid #ddd
- Width: 200px
- Border Radius: 3px

#### Post Card
- Background: White
- Border Bottom: 1px solid #eee
- Padding: 15px
- Hover: #fafafa background

#### Buttons
- Primary: #5a9a3c background, white text
- Secondary: White background, #ddd border
- Border Radius: 3px
- Padding: 8px 20px

---

## Success Metrics

### Design Goals
- [ ] Page load time < 2 seconds
- [ ] Mobile responsive (100% pass)
- [ ] Accessibility score > 90%
- [ ] User satisfaction > 85%

### User Experience
- [ ] Bounce rate decrease by 20%
- [ ] Session duration increase by 30%
- [ ] Engagement rate increase by 25%

### Technical
- [ ] CSS file size < 50KB
- [ ] No JavaScript errors
- [ ] Cross-browser compatibility 100%

---

## Risks & Mitigation

| Risk | Impact | Probability | Mitigation |
|------|--------|-------------|------------|
| User resistance to change | High | Medium | Gradual rollout, user feedback |
| Mobile layout issues | Medium | Low | Extensive mobile testing |
| Performance degradation | High | Low | Performance monitoring |
| Browser compatibility | Medium | Low | Cross-browser testing |
| Data migration errors | High | Very Low | Thorough testing, backups |

---

## Resources Needed

### Team
- [ ] 1 Frontend Developer (you)
- [ ] 1 Designer (optional - for refinements)
- [ ] 1 QA Tester (optional)

### Tools
- [x] Code editor (VS Code)
- [x] Browser DevTools
- [ ] Performance testing tools (Lighthouse)
- [ ] Accessibility testing tools (WAVE)
- [ ] Cross-browser testing (BrowserStack - optional)

### Time Estimate
- Design System: 8 hours ✅
- Templates: 12 hours (50% complete)
- Page Integration: 16 hours
- Interactive Elements: 12 hours
- Data Integration: 10 hours
- Testing & Polish: 10 hours
**Total**: ~68 hours (1.5 weeks full-time)

---

## Next Immediate Steps

1. **Complete sidebar widgets** (2 hours)
   - Newest users display
   - Active users display
   - Popular users display
   - Announcements display

2. **Test current implementation** (1 hour)
   - Load `index_sosyomat.php`
   - Check layout in different browsers
   - Test mobile responsiveness

3. **Create remaining templates** (4 hours)
   - Profile page
   - Search page
   - Post detail page

4. **Implement tag system** (3 hours)
   - Tag extraction
   - Tag navigation
   - Tag search

5. **User testing** (2 hours)
   - Get feedback from 3-5 users
   - Make adjustments

---

## Changelog

### 2026-02-02
- ✅ Created sosyomat.css
- ✅ Created index_sosyomat.php
- ✅ Created header_sosyomat.php
- ✅ Created footer_sosyomat.php
- ✅ Created post-card-sosyomat.php
- ✅ Created roadmap document

### Future Updates
- TBD: Completion of sidebar widgets
- TBD: Tag system implementation
- TBD: Additional page conversions

---

## Notes

- Design inspired by sosyomat.com (archived 2010 version)
- Focus on simplicity and usability
- Maintain existing functionality
- Ensure backward compatibility
- Keep district mesh network feature intact
- Responsive design is priority

---

**Last Updated**: February 2, 2026  
**Version**: 1.0  
**Status**: Phase 2 - 40% Complete
