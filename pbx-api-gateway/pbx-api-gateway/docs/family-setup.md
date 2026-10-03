# Adding family members (step by step)

This guide is for whoever runs the phone system. No tech background needed, just follow the steps.

## The idea in one minute

There are **two different things** to set up for each person:

| | What it is | Who uses it | How many |
|---|---|---|---|
| **Phone account** | The username + password you type into a phone app (Zoiper), a desk phone or Home Assistant | the phone itself | **one per phone / device** |
| **Portal login** | An e-mail + password for the website where they see their own calls | the person | **one per person** |

So one person can have **several phones, but only one login**:

```
Mom  (portal login: mom)
 ├── Mom – iPhone        phone account, people at home dial 8804
 └── Mom – Kitchen       phone account, people at home dial 8805

Bro  (portal login: bro)
 ├── Bro – Android       phone account, 8806
 ├── Bro – Desk phone    phone account, 8807
 └── Bro – Home Assistant phone account, 8808
```

**Why one phone account per device, and not one shared account?**
- You decide per phone what it may call (the desk phone can call the house, the Android can too, but only you allow outside numbers).
- If a phone is lost, you turn off just that one. The others keep working.
- The dashboard shows each phone separately, and a shared account would set off the "signed in from two places at once" alarm all day.

---

## Part 1: make a phone account for each phone

On the PBX web page: **Applications → API Users → Users tab → Add user** (scroll down).

1. **Name:** person + device, for example `Mom – iPhone`. This is the name you'll see everywhere.
2. **Reach extension:** leave empty. It picks the next free number (8801, 8802 ...). That's the number your house phones dial to ring this phone.
3. **May call these extensions:** tick the house phones (and other family phones) this phone may call. Calls between them are **free**.
4. **Account type:**
   - **VPN account** (recommended): the phone connects through your VPN app or home Wi-Fi. Safest.
   - **Public account**: for someone who won't install a VPN app. It can **never** call outside numbers or 911
     (only through the PIN-protected dial-out code, if you turn that on).
5. **Outside calls: leave them OFF** unless this phone really needs to call regular phone numbers.
   Outside calls go over *your* phone service and can cost *you* money. 911 is OFF by default (see the 911 warning in the README).
6. **Max calls at once / Max minutes per call:** 1 and 120 are good defaults.
7. Click **Create user**. A box appears with the **username, password and QR codes**: this is the only time the
   password is shown. Use **Download image**, **Email** or **Copy text** to send it to the person, or just set the phone up yourself.

Repeat for every phone. Setting up the phone itself (Zoiper, a desk phone, Home Assistant) uses the same three
things: **username**, **password**, **server**. See *Add users & set up Zoiper* in the README.

---

## Part 2: make one portal login per person (Authentik)

Authentik is the sign-in system in front of the portal. Do these once, on your Authentik admin page
(**Admin interface**). Menu names can differ a little between Authentik versions.

**Only the first time:**
1. **Directory → Groups → Create:** name it `phone-users`.
2. **Applications → Phone portal → Policy / Group / User Bindings → Bind existing group:** pick `phone-users`.
   Now only people in that group can open the portal.
3. Check that your **admin** app (the remote panel) is bound to **you only**, so family members can't open it.

**For each person:**
1. **Directory → Users → Create**
   - **Username:** short and simple, for example `mom`. Write it down; you'll need it in Part 3.
   - **Name** and **E-mail:** theirs.
2. Open the new user and either:
   - **Set password** and tell them the password, or
   - create a **recovery link** and send it, so they choose their own password.
3. On the user's **Groups** tab, add them to `phone-users`.
4. **Recommended:** ask them to sign in once and turn on 2-step sign-in (an authenticator app) in their own settings.

---

## Part 3: connect the login to their phones

Back on the PBX page, for **each** of that person's phone accounts:

1. Click **Edit**.
2. In **User portal login**, type their Authentik **username** (for example `mom`). Use the **same** username on all of their phones.
3. Click **Save**.

(The remote admin panel has the same field.)

---

## Part 4: what they see

They open the portal address you set up (for example `https://phone.yourdomain.com`), sign in, and see:

- **one card per phone**: online or not, the number people at home dial, and what that phone can call
- the **Zoiper settings** for each phone and a **Get a new password** button per phone
- their **calls**, **sign-ins** and **failed attempts**, with a column that says which phone

They never see your other users, never see a password after it's been set, and can't change anything except
getting a new password for their own phones.

---

## Keeping phone bills low (checklist)

- [ ] **Outside calls OFF** on every phone that doesn't truly need them. Calls to house phones and other family phones are free.
- [ ] **International OFF.** Premium numbers (900/976) are always blocked anyway.
- [ ] Public accounts: only give the **dial-out code** to people you trust, with a PIN they keep private.
- [ ] Set **Max minutes per call** (for example 60) so a forgotten call can't run all night.
- [ ] Don't tick house extensions that **forward to an outside number** (call forward / follow-me): those forwarded calls cost money.
- [ ] Turn on **E-mail alerts** (Settings) so you hear about failed sign-ins or a phone signed in from two places.
- [ ] Glance at the **Dashboard** now and then: who's signed in, who's on a call.

---

## Common questions

**"No phone account is linked to …" when they open the portal**
The username on the PBX (User portal login) doesn't match their Authentik username. Fix the spelling on the phone account's Edit form.

**They forgot a phone's password**
They click **Get a new password** on that phone's card in the portal, or you click **New password** on the PBX page.
Then type the new one into that phone.

**A phone was lost or stolen**
Click **New password** for that phone (the old password stops working at once), or untick **Enabled** to turn it off.
Their other phones keep working.

**Someone leaves the family plan**
Delete their phone accounts on the PBX page, and deactivate their user in Authentik.

**The dashboard says "signed in from 2 places at once"**
Two different devices are using the *same* phone account. If that's on purpose, give the second device its own
phone account (Part 1). If not, click **New password** for that account.
