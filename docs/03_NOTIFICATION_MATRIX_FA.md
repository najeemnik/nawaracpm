# NawAra CPM — Notification، Reminder و Sound Policy

**وضعیت:** مبنای مورد تأیید برای پیاده‌سازی  
**هدف:** رساندن notification درست، به شخص درست، در زمان درست؛ بدون spam و بدون افشای data داخلی به Client.

---

## 1. Channelهای Notification

### نسخهٔ اول

| Channel | کاربرد |
|---|---|
| In-app notification center | همیشه موجود؛ unread/read، لینک مستقیم به object |
| In-app sound | وقتی PWA/browser باز است و user sound را فعال کرده |
| Web/PWA Push | وقتی app در background یا بسته است، با اجازهٔ user/device |

### نسخه‌های بعدی (اختیاری)

```text
Email
WhatsApp
Telegram
SMS
Daily digest
```

---

## 2. محدودیت واقعی Sound در Browser/PWA

### وقتی app باز است

بعد از اولین interaction user با app، سیستم می‌تواند صدای کوتاه notification پخش کند.

### وقتی app بسته یا background است

Push Notification توسط Android/iOS/Desktop OS نشان داده می‌شود. صدای آن معمولاً طبق setting سیستم‌عامل و browser است.

> PWA نمی‌تواند در همهٔ deviceها صدای custom را به زور پخش کند، مخصوصاً در iPhone/Safari. بنابراین طراحی باید روی push system sound + in-app sound تکیه کند.

---

## 3. Preference هر User

هر user تنظیمات شخصی دارد:

```text
Enable in-app notifications          on / off
Enable notification sound            on / off
Enable push notifications            on / off
Task assignment notifications        on / off
Review notifications                 on / off
Deadline reminders                   on / off
Client update notifications          on / off
Quiet hours                          optional
Timezone                             required
```

Admin می‌تواند notification بحرانی مانند overdue escalation را از quiet hours مستثنا کند، اگر policy پروژه چنین باشد.

---

## 4. Event → Recipient Matrix

| Event | Recipient | In-app | Sound | Push | Reminder/Policy |
|---|---|:---:|:---:|:---:|---|
| Task assigned | همهٔ Assigneeها | ✓ | ✓ | ✓ | فوری |
| Assignee added to existing task | Assignee جدید | ✓ | ✓ | ✓ | فوری |
| Deadline changed | همهٔ Assigneeها + Admin مرتبط | ✓ | ✓ | ✓ | فوری |
| Task started | Admin/Project Admin، اگر فعال باشد | ✓ | اختیاری | اختیاری | فوری |
| Task blocked | Responsible Admin + Project Admin | ✓ | ✓ | ✓ | فوری و high priority |
| Comment / @mention | Mentioned user / watchers | ✓ | ✓ | ✓ | فوری |
| File uploaded for task | Reviewer/Admin مرتبط | ✓ | اختیاری | اختیاری | فوری |
| Submitted for review | Reviewerها + Responsible Admin | ✓ | ✓ | ✓ | فوری |
| Review approved | Assigneeها | ✓ | ✓ | ✓ | فوری |
| Revision requested | Assigneeها | ✓ | ✓ | ✓ | فوری و high priority |
| Task completed without review | Admin، اگر toggle فعال باشد | ✓ | اختیاری | اختیاری | فوری |
| Task cancelled | Assigneeها + watchers | ✓ | اختیاری | ✓ | فوری |
| Client publication | Client recipientها | ✓ | ✓ | ✓ | فوری |
| Client approved | Admin + Assigneeها | ✓ | ✓ | ✓ | فوری |
| Client requested changes | Admin + Assigneeها | ✓ | ✓ | ✓ | فوری و high priority |
| Report published | Client selected + Admin | ✓ | اختیاری | ✓ | فوری |
| Section/Milestone reached | Client selected + Project team | ✓ | اختیاری | ✓ | طبق project rule |

---

## 5. Deadline Reminder Policy

Default policy قابل تغییر per-project یا per-task است:

```text
3 days before due date      → Assignee reminder
1 day before due date       → Assignee reminder
Due date/time               → Assignee alert
1 day overdue               → Assignee + Responsible Admin
3 days overdue              → Head Engineer / Project health alert
```

برای Taskهای با priority `critical` می‌توان rule جدا داشت:

```text
1 day before → Assignee + Admin
At due time  → Assignee + Admin
Overdue      → high-risk alert
```

---

## 6. Anti-spam Ruleها

1. یک event فقط یک notification منطقی برای هر recipient ایجاد کند.
2. updateهای پی‌درپی status در مدت کوتاه group شوند.
3. user بتواند non-critical channelها را mute کند.
4. Client هرگز notification مربوط Task داخلی دریافت نکند.
5. reminder ارسال‌شده در notification history ثبت شود.
6. failed push/email قابل retry باشد، اما retry loop نامحدود نباشد.

---

## 7. Notification Data Model پیشنهادی

```text
pm_notifications
- id
- recipient_user_id
- project_id
- task_id
- event_type
- title
- body
- priority
- deep_link
- read_at
- delivered_at
- created_at

pm_notification_preferences
- user_id
- in_app_enabled
- sound_enabled
- push_enabled
- quiet_hours_start
- quiet_hours_end
- timezone
- event_preferences_json

pm_push_subscriptions
- user_id
- endpoint
- public_key
- auth_key
- user_agent
- last_seen_at
- revoked_at

pm_notification_deliveries
- notification_id
- channel
- status
- attempts
- delivered_at
- failure_reason
```

---

## 8. Scheduler / Background Job Requirement

Reminder و push قابل اعتماد نیاز به process server-side دارد:

```text
Every few minutes:
1. Find tasks nearing due time
2. Create deduplicated reminders
3. Send push/in-app/email as allowed
4. Mark delivery result
5. Escalate overdue tasks according to policy
```

PWA به‌تنهایی نمی‌تواند وقتی بسته است deadlineها را به شکل قابل اعتماد بررسی کند.

---

## 9. Security و Privacy Ruleها

- Push subscriptionها secret محسوب می‌شوند و نباید در Git ثبت شوند.
- notification body برای Client نباید نام/جزئیات internal task را افشا کند.
- deep link باید پس از بازشدن دوباره permission server-side را چک کند.
- Userی که deactivate شده notification جدید نمی‌گیرد.
- notificationهای حساس باید minimum data لازم را داشته باشند.
- AI هرگز permission bypass نمی‌کند؛ پاسخ AI فقط از data مجاز همان user ساخته می‌شود.

---

## 10. معیار پذیرش مرحلهٔ پیاده‌سازی

- [ ] Task جدید فوراً به تمام Assigneeهای مربوط notification دهد.
- [ ] Submit for Review به reviewer/Admin notification دهد.
- [ ] Approve/Revision به Assigneeها notification دهد.
- [ ] Client فقط notificationهای publish‌شده و مجاز خودش را بگیرد.
- [ ] sound در app باز، با preference user کار کند.
- [ ] push subscription فقط بعد از permission device فعال شود.
- [ ] reminderها duplicate نشوند.
- [ ] overdue escalation به Admin طبق policy کار کند.
- [ ] تمام notificationها با access control و audit log سازگار باشند.
